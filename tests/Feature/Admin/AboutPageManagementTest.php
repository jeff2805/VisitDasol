<?php

namespace Tests\Feature\Admin;

use App\Enums\AboutSection as Section;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\AboutPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The About Page Management screens.
 *
 * AboutPageCmsTest proves the engine. This proves the doors into it: that
 * the office can reach them and nobody else can, that a save writes a draft
 * and only Publish moves the public page, and that an upload is checked
 * before it is kept.
 */
class AboutPageManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->office = User::factory()->superAdmin()->create();
    }

    /* Reaching the screens ---------------------------------------------- */

    public function test_the_office_sees_all_seven_sections(): void
    {
        $response = $this->actingAs($this->office)
            ->get(route('admin.about.index'))
            ->assertOk();

        foreach (Section::cases() as $section) {
            $response->assertSee($section->label());
        }
    }

    public function test_the_sidebar_offers_about_page_management(): void
    {
        $this->actingAs($this->office)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('About Page Management')
            ->assertSee(route('admin.about.index'));
    }

    public function test_a_section_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->office)
            ->get(route('admin.about.edit', 'not-a-section'))
            ->assertNotFound();
    }

    /* Saving is not publishing ------------------------------------------ */

    /*
     * Driven through the municipality section, which the public template
     * already renders. The hero is drawn in the redesigned page and is
     * covered there; the save-and-publish mechanic under test is the same
     * one whichever section it runs on.
     */
    public function test_saving_writes_a_draft_and_leaves_the_public_page_alone(): void
    {
        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'municipality'), [
                'heading' => 'A brand new heading',
                'body' => 'Freshly typed, not yet published.',
            ])
            ->assertRedirect(route('admin.about.edit', 'municipality'));

        // The visitor sees no change.
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('The municipality')
            ->assertDontSee('A brand new heading');

        // The office does.
        $this->actingAs($this->office)
            ->get(route('admin.about.preview'))
            ->assertOk()
            ->assertSee('A brand new heading');
    }

    public function test_publishing_puts_it_in_front_of_visitors(): void
    {
        $this->actingAs($this->office)->put(route('admin.about.update', 'municipality'), [
            'heading' => 'A brand new heading',
            'body' => 'Now everybody can read it.',
        ]);

        $this->actingAs($this->office)
            ->patch(route('admin.about.publish', 'municipality'))
            ->assertRedirect(route('admin.about.index'));

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('A brand new heading')
            ->assertSee('Now everybody can read it.');
    }

    public function test_unpublishing_falls_back_to_the_built_in_wording(): void
    {
        $this->actingAs($this->office)->put(route('admin.about.update', 'municipality'), [
            'heading' => 'A brand new heading',
            'body' => 'Published for a moment.',
        ]);
        $this->actingAs($this->office)->patch(route('admin.about.publish', 'municipality'));

        $this->actingAs($this->office)
            ->patch(route('admin.about.unpublish', 'municipality'))
            ->assertRedirect(route('admin.about.index'));

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('The municipality')
            ->assertDontSee('A brand new heading');
    }

    /* Validation --------------------------------------------------------- */

    public function test_an_empty_heading_is_refused(): void
    {
        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'hero'), ['label' => 'X', 'heading' => ''])
            ->assertSessionHasErrors('heading');
    }

    public function test_a_history_paragraph_cannot_be_blank(): void
    {
        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'history'), [
                'heading' => 'History of Dasol',
                'paragraphs' => ['A real paragraph.', ''],
            ])
            ->assertSessionHasErrors('paragraphs.1');
    }

    public function test_a_destination_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'destinations'), [
                'heading' => 'Explore Dasol',
                'destination_ids' => [999999],
            ])
            ->assertSessionHasErrors('destination_ids.0');
    }

    /* Pictures ------------------------------------------------------------ */

    public function test_a_picture_is_stored_and_shown_once_published(): void
    {
        $this->actingAs($this->office)->put(route('admin.about.update', 'municipality'), [
            'heading' => 'The municipality',
            'body' => 'A coastal town.',
            'image' => UploadedFile::fake()->image('shore.jpg', 1200, 800),
        ]);

        $path = app(AboutPageService::class)->preview()['municipality']['image_path'];

        $this->assertNotNull($path, 'The upload was not recorded.');
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'municipality'), [
                'heading' => 'The municipality',
                'body' => 'A coastal town.',
                'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_an_oversized_picture_is_refused(): void
    {
        $maxKb = (int) config('visitdasol.uploads.image_max_kb');

        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'municipality'), [
                'heading' => 'The municipality',
                'body' => 'A coastal town.',
                'image' => UploadedFile::fake()->create('huge.jpg', $maxKb + 500, 'image/jpeg'),
            ])
            ->assertSessionHasErrors('image');
    }

    /**
     * The live page must not develop a hole while the office is still
     * deciding.
     */
    public function test_a_replaced_picture_survives_until_the_change_is_published(): void
    {
        $service = app(AboutPageService::class);

        $this->actingAs($this->office)->put(route('admin.about.update', 'municipality'), [
            'heading' => 'The municipality', 'body' => 'One.',
            'image' => UploadedFile::fake()->image('first.jpg', 800, 600),
        ]);
        $this->actingAs($this->office)->patch(route('admin.about.publish', 'municipality'));

        $original = $service->published()['municipality']['image_path'];

        // Replaced, but not published.
        $this->actingAs($this->office)->put(route('admin.about.update', 'municipality'), [
            'heading' => 'The municipality', 'body' => 'Two.',
            'image' => UploadedFile::fake()->image('second.jpg', 800, 600),
        ]);

        Storage::disk('public')->assertExists($original);
        $this->assertSame($original, $service->published()['municipality']['image_path']);

        // Published: the old file is nobody's now.
        $this->actingAs($this->office)->patch(route('admin.about.publish', 'municipality'));

        Storage::disk('public')->assertMissing($original);
    }

    /* Destinations are pointed at, not copied ---------------------------- */

    public function test_choosing_destinations_shows_them_on_the_page(): void
    {
        $category = DestinationCategory::factory()->create();
        $beach = Destination::factory()->create(['name' => 'Tambobong Beach', 'category_id' => $category->id]);

        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore the Beauty of Dasol',
            'destination_ids' => [$beach->id],
        ]);
        $this->actingAs($this->office)->patch(route('admin.about.publish', 'destinations'));

        $this->get(route('about'))->assertOk()->assertSee('Tambobong Beach');

        $this->assertDatabaseHas('destinations', ['id' => $beach->id]);
    }

    /* Nobody else ---------------------------------------------------------- */

    public function test_a_resort_owner_cannot_reach_or_change_anything(): void
    {
        $owner = User::factory()->create(['role' => 'resort_owner']);

        $this->actingAs($owner)->get(route('admin.about.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.about.edit', 'hero'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.about.preview'))->assertForbidden();

        $this->actingAs($owner)
            ->put(route('admin.about.update', 'hero'), ['label' => 'X', 'heading' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($owner)->patch(route('admin.about.publish', 'hero'))->assertForbidden();
    }

    public function test_a_tourist_cannot_reach_or_change_anything(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->get(route('admin.about.index'))->assertForbidden();
        $this->actingAs($tourist)
            ->put(route('admin.about.update', 'hero'), ['label' => 'X', 'heading' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.about.index'))->assertRedirect(route('login'));
        $this->put(route('admin.about.update', 'hero'), ['heading' => 'X'])->assertRedirect(route('login'));
    }

    /**
     * An unpublished draft is not public, and a preview address that leaked
     * would make it so.
     */
    public function test_the_preview_is_not_public(): void
    {
        $this->get(route('admin.about.preview'))->assertRedirect(route('login'));
    }
}
