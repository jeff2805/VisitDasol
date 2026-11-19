<?php

namespace Tests\Feature\Owner;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tourism office's announcements, read inside the owner's dashboard.
 *
 * The same published announcements the public site carries. What matters
 * here is that a DRAFT never reaches a resort: the office writing an
 * advisory is not the office issuing one, and a resort acting on a half
 * written storm warning is the failure this guards against.
 */
class OwnerAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'resort_owner']);
        Resort::factory()->active()->create(['owner_id' => $this->owner->id]);
    }

    private function published(array $extra = []): Announcement
    {
        return Announcement::factory()->create(array_merge([
            'status' => AnnouncementStatus::Published,
            'published_at' => now()->subDay(),
        ], $extra));
    }

    public function test_an_owner_reads_the_offices_announcements_in_their_dashboard(): void
    {
        $this->published(['title' => 'Red tide advisory for Dasol bay']);

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.index'))
            ->assertOk()
            ->assertSee('Red tide advisory for Dasol bay');
    }

    public function test_the_sidebar_offers_announcements(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Announcements')
            ->assertSee(route('owner.announcements.index'));
    }

    public function test_one_announcement_opens_on_its_own_page(): void
    {
        $announcement = $this->published([
            'title' => 'Boat operators must renew permits',
            'body' => 'Renew at the municipal hall before the end of the month.',
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.show', $announcement))
            ->assertOk()
            ->assertSee('Boat operators must renew permits')
            ->assertSee('Renew at the municipal hall');
    }

    /**
     * The one that matters: an advisory nobody has issued yet.
     */
    public function test_a_draft_never_reaches_a_resort(): void
    {
        $draft = Announcement::factory()->create([
            'title' => 'Still being written',
            'status' => AnnouncementStatus::Draft,
            'published_at' => null,
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.index'))
            ->assertOk()
            ->assertDontSee('Still being written');

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.show', $draft))
            ->assertNotFound();
    }

    public function test_an_announcement_scheduled_for_later_is_not_shown_yet(): void
    {
        $future = $this->published([
            'title' => 'Fiesta road closures',
            'published_at' => now()->addWeek(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.index'))
            ->assertOk()
            ->assertDontSee('Fiesta road closures');

        $this->actingAs($this->owner)
            ->get(route('owner.announcements.show', $future))
            ->assertNotFound();
    }

    public function test_a_tourist_cannot_open_the_owner_announcement_pages(): void
    {
        $announcement = $this->published();
        $tourist = User::factory()->create();

        $this->actingAs($tourist)
            ->get(route('owner.announcements.index'))
            ->assertForbidden();

        $this->actingAs($tourist)
            ->get(route('owner.announcements.show', $announcement))
            ->assertForbidden();
    }
}
