<?php

namespace Tests\Feature\Admin;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-15 09:00:00');
        $this->mto = User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Holiday office hours',
            'excerpt' => 'The tourism office keeps shorter hours over the holidays.',
            'body' => 'The Municipal Tourism Office will be open from 8 AM to 12 noon on December 24 and 31.',
        ], $overrides);
    }

    public function test_a_new_announcement_starts_as_a_draft(): void
    {
        $this->actingAs($this->mto)->post(route('admin.announcements.store'), $this->payload([
            // Must be ignored: publishing is a separate, deliberate step.
            'status' => 'published',
            'is_featured' => true,
        ]))->assertRedirect();

        $announcement = Announcement::sole();
        $this->assertSame(AnnouncementStatus::Draft, $announcement->status);
        $this->assertFalse($announcement->is_featured);
        $this->assertNull($announcement->published_at);
        $this->assertSame($this->mto->id, $announcement->created_by);
        $this->assertSame('holiday-office-hours', $announcement->slug);
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.created', 'subject_id' => $announcement->id]);

        // Drafts are invisible to visitors.
        $this->post(route('logout'));
        $this->get(route('announcements.index'))->assertOk()->assertDontSee('Holiday office hours');
        $this->get(route('announcements.show', $announcement))->assertNotFound();
    }

    public function test_publishing_makes_it_public(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Holiday office hours']);

        $this->actingAs($this->mto)->patch(route('admin.announcements.publish', $announcement))
            ->assertRedirect()->assertSessionHas('success');

        $announcement->refresh();
        $this->assertSame(AnnouncementStatus::Published, $announcement->status);
        $this->assertNotNull($announcement->published_at);
        $this->assertTrue($announcement->isPublished());
        $this->assertDatabaseHas('activity_logs', ['action' => 'announcement.published', 'subject_id' => $announcement->id]);

        $this->post(route('logout'));
        $this->get(route('announcements.index'))->assertOk()->assertSee('Holiday office hours');
        $this->get(route('announcements.show', $announcement))->assertOk();
        $this->get(route('home'))->assertOk()->assertSee('Holiday office hours');
    }

    public function test_an_announcement_can_be_scheduled_for_later(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Fiesta schedule']);

        $this->actingAs($this->mto)->patch(route('admin.announcements.publish', $announcement), [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'published_at' => '2030-07-01 08:00',
        ])->assertRedirect();

        $announcement->refresh();
        $this->assertSame(AnnouncementStatus::Published, $announcement->status);
        $this->assertFalse($announcement->isPublished(), 'Not yet, because the date has not arrived.');

        $this->get(route('announcements.index'))->assertDontSee('Fiesta schedule');
        $this->get(route('announcements.show', $announcement))->assertNotFound();

        $this->travelTo('2030-07-01 09:00:00');
        $this->get(route('announcements.index'))->assertSee('Fiesta schedule');
    }

    public function test_taking_an_announcement_down_hides_it_without_deleting_it(): void
    {
        $announcement = Announcement::factory()->published()->create(['title' => 'Old advisory', 'is_featured' => true]);

        $this->actingAs($this->mto)->patch(route('admin.announcements.unpublish', $announcement))->assertRedirect();

        $announcement->refresh();
        $this->assertSame(AnnouncementStatus::Draft, $announcement->status);
        $this->assertFalse($announcement->is_featured, 'Something hidden cannot stay pinned.');
        $this->assertSame(1, Announcement::count());

        $this->get(route('announcements.index'))->assertDontSee('Old advisory');
    }

    public function test_pinned_announcements_come_first_for_visitors(): void
    {
        Announcement::factory()->published(now()->subHour())->create(['title' => 'Newer notice']);
        $pinned = Announcement::factory()->published(now()->subWeek())->create(['title' => 'Pinned notice']);

        $this->actingAs($this->mto)->patch(route('admin.announcements.feature', $pinned))->assertRedirect();
        $this->assertTrue($pinned->fresh()->is_featured);

        $this->post(route('logout'));
        $this->get(route('announcements.index'))->assertSeeInOrder(['Pinned notice', 'Newer notice']);
        $this->get(route('home'))->assertSeeInOrder(['Pinned notice', 'Newer notice']);
    }

    public function test_only_something_visitors_can_read_may_be_pinned(): void
    {
        $draft = Announcement::factory()->create();
        $scheduled = Announcement::factory()->published(now()->addWeek())->create();

        $this->actingAs($this->mto)->patch(route('admin.announcements.feature', $draft))->assertForbidden();
        $this->actingAs($this->mto)->patch(route('admin.announcements.feature', $scheduled))->assertForbidden();

        $this->assertFalse($draft->fresh()->is_featured);
        $this->assertFalse($scheduled->fresh()->is_featured);
    }

    public function test_editing_and_validation(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->mto)->put(route('admin.announcements.update', $announcement), $this->payload([
            'title' => 'Updated title for the notice',
        ]))->assertRedirect(route('admin.announcements.index'));

        $this->assertSame('Updated title for the notice', $announcement->fresh()->title);

        foreach ([['title' => ''], ['body' => 'Too short.'], ['title' => 'Hi']] as $bad) {
            $this->actingAs($this->mto)
                ->put(route('admin.announcements.update', $announcement), $this->payload($bad))
                ->assertSessionHasErrors(array_key_first($bad));
        }
    }

    public function test_a_picture_can_be_added_and_removed(): void
    {
        Storage::fake('public');

        $this->actingAs($this->mto)->post(route('admin.announcements.store'), $this->payload([
            'image' => UploadedFile::fake()->image('notice.jpg', 1000, 700),
        ]));

        $announcement = Announcement::sole();
        $path = $announcement->image;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->mto)->put(route('admin.announcements.update', $announcement), $this->payload(['remove_image' => '1']));

        $this->assertNull($announcement->fresh()->image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_removes_the_announcement_and_its_picture(): void
    {
        Storage::fake('public');
        $this->actingAs($this->mto)->post(route('admin.announcements.store'), $this->payload([
            'image' => UploadedFile::fake()->image('notice.jpg', 1000, 700),
        ]));
        $announcement = Announcement::sole();
        $path = $announcement->image;

        $this->actingAs($this->mto)->delete(route('admin.announcements.destroy', $announcement))->assertRedirect();

        $this->assertSame(0, Announcement::count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_announcement_text_is_shown_as_typed_and_never_run(): void
    {
        $announcement = Announcement::factory()->published()->create([
            'title' => 'Advisory <script>alert(1)</script>',
            'body' => 'Contact us at <img src=x onerror=alert(2)> the office.',
        ]);

        $response = $this->get(route('announcements.show', $announcement));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_only_the_office_may_manage_announcements(): void
    {
        $announcement = Announcement::factory()->published()->create();

        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.announcements.index'))->assertForbidden();
            $this->actingAs($intruder)->post(route('admin.announcements.store'), $this->payload())->assertForbidden();
            $this->actingAs($intruder)->patch(route('admin.announcements.unpublish', $announcement))->assertForbidden();
            $this->actingAs($intruder)->delete(route('admin.announcements.destroy', $announcement))->assertForbidden();
        }

        $this->assertSame(1, Announcement::count());
        $this->assertSame(AnnouncementStatus::Published, $announcement->fresh()->status);
    }

    public function test_the_list_can_be_filtered_and_searched(): void
    {
        Announcement::factory()->create(['title' => 'Draft about parking']);
        Announcement::factory()->published()->create(['title' => 'Published about ferries']);

        $this->actingAs($this->mto)->get(route('admin.announcements.index', ['status' => 'draft']))
            ->assertSee('Draft about parking')->assertDontSee('Published about ferries');

        $this->actingAs($this->mto)->get(route('admin.announcements.index', ['q' => 'ferries']))
            ->assertSee('Published about ferries')->assertDontSee('Draft about parking');
    }
}
