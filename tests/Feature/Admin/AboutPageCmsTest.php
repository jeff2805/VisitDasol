<?php

namespace Tests\Feature\Admin;

use App\Enums\AboutSection as Section;
use App\Models\AboutSection;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\AboutPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The content engine behind the About page.
 *
 * THE RULE EVERYTHING HERE PROTECTS: saving is not publishing. An office
 * typing into a box must be able to stop half way, go to lunch, and know
 * with certainty that nobody visiting the site saw the half-written
 * sentence. The public page reads one column and the editor writes another,
 * which is a stronger promise than remembering to check a flag.
 */
class AboutPageCmsTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private AboutPageService $about;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->superAdmin()->create();
        $this->about = app(AboutPageService::class);
    }

    /* Defaults ---------------------------------------------------------- */

    public function test_every_section_reads_properly_before_anybody_edits_it(): void
    {
        $live = $this->about->published();

        $this->assertCount(count(Section::cases()), $live);
        $this->assertSame('Discover Dasol, Pangasinan', $live['hero']['heading']);
        $this->assertSame('History of Dasol', $live['history']['heading']);
        $this->assertCount(6, $live['history']['paragraphs']);
        $this->assertTrue($live['owner_cta']['visible']);
    }

    /* Draft and publish -------------------------------------------------- */

    public function test_a_saved_draft_does_not_reach_the_public(): void
    {
        $this->about->saveDraft(Section::Hero, ['heading' => 'Half written'], $this->office);

        $this->assertSame(
            'Discover Dasol, Pangasinan',
            $this->about->published()['hero']['heading'],
            'A draft must never show on the public page.'
        );

        $this->assertSame('Half written', $this->about->preview()['hero']['heading']);
    }

    public function test_publishing_puts_the_draft_in_front_of_the_public(): void
    {
        $this->about->saveDraft(Section::Hero, ['heading' => 'Welcome to Dasol'], $this->office);
        $this->about->publish(Section::Hero, $this->office);

        $this->assertSame('Welcome to Dasol', $this->about->published()['hero']['heading']);

        $row = AboutSection::query()->where('section_key', 'hero')->firstOrFail();

        $this->assertNull($row->draft, 'The draft is spent once published.');
        $this->assertSame($this->office->id, $row->published_by);
        $this->assertNotNull($row->published_at);
    }

    public function test_unpublishing_takes_it_down_but_keeps_the_words(): void
    {
        $this->about->saveDraft(Section::Hero, ['heading' => 'Welcome to Dasol'], $this->office);
        $this->about->publish(Section::Hero, $this->office);

        $this->about->unpublish(Section::Hero, $this->office);

        $this->assertSame(
            'Discover Dasol, Pangasinan',
            $this->about->published()['hero']['heading'],
            'The public falls back to the default.'
        );

        $this->assertSame(
            'Welcome to Dasol',
            $this->about->preview()['hero']['heading'],
            'The office has not lost what they wrote.'
        );
    }

    public function test_a_half_posted_form_does_not_wipe_the_rest_of_a_section(): void
    {
        $this->about->saveDraft(Section::Location, [
            'province' => 'Pangasinan',
            'postal_code' => '2411',
        ], $this->office);

        // A later save that mentions only one field.
        $this->about->saveDraft(Section::Location, ['postal_code' => '2412'], $this->office);

        $working = $this->about->preview()['location'];

        $this->assertSame('2412', $working['postal_code']);
        $this->assertSame('Pangasinan', $working['province'], 'The untouched field survived.');
    }

    /**
     * A field added to a section after something was published must not
     * leave a blank heading on a live site.
     */
    public function test_a_payload_written_before_a_field_existed_still_answers(): void
    {
        $row = $this->about->row(Section::Hero);
        $row->forceFill(['published' => ['heading' => 'Only a heading'], 'published_at' => now()])->save();

        $live = $this->about->published()['hero'];

        $this->assertSame('Only a heading', $live['heading']);
        $this->assertSame('ABOUT DASOL', $live['label'], 'Missing keys fall back to the default.');
    }

    public function test_the_office_can_see_which_sections_are_waiting(): void
    {
        $this->about->saveDraft(Section::Heritage, ['heading' => 'Salt'], $this->office);

        $rows = $this->about->all();

        $this->assertTrue($rows['heritage']->hasUnpublishedChanges());
        $this->assertFalse($rows['hero']->hasUnpublishedChanges());
    }

    /* Destinations are reused, never copied ------------------------------ */

    public function test_the_page_shows_the_offices_chosen_destinations_in_order(): void
    {
        $category = DestinationCategory::factory()->create();

        $beach = Destination::factory()->create(['name' => 'Tambobong Beach', 'category_id' => $category->id]);
        $deck = Destination::factory()->create(['name' => 'Malacapas View Deck', 'category_id' => $category->id]);
        $cove = Destination::factory()->create(['name' => 'Balinmanok Beach', 'category_id' => $category->id]);

        $chosen = $this->about->destinationsFor([$cove->id, $beach->id, $deck->id]);

        $this->assertSame(
            ['Balinmanok Beach', 'Tambobong Beach', 'Malacapas View Deck'],
            $chosen->pluck('name')->all(),
            'The order is the one the office set, not the database order.'
        );
    }

    public function test_taking_a_destination_off_the_about_page_leaves_the_record_alone(): void
    {
        $category = DestinationCategory::factory()->create();
        $beach = Destination::factory()->create(['name' => 'Tambobong Beach', 'category_id' => $category->id]);
        $deck = Destination::factory()->create(['name' => 'Malacapas View Deck', 'category_id' => $category->id]);

        $this->about->saveDraft(Section::Destinations, [
            'destination_ids' => [$beach->id],
        ], $this->office);
        $this->about->publish(Section::Destinations, $this->office);

        $shown = $this->about->destinationsFor($this->about->published()['destinations']['destination_ids']);

        $this->assertSame(['Tambobong Beach'], $shown->pluck('name')->all());
        $this->assertDatabaseHas('destinations', ['id' => $deck->id, 'name' => 'Malacapas View Deck']);
    }

    public function test_a_destination_deleted_since_does_not_break_the_page(): void
    {
        $category = DestinationCategory::factory()->create();
        $beach = Destination::factory()->create(['name' => 'Tambobong Beach', 'category_id' => $category->id]);

        $shown = $this->about->destinationsFor([$beach->id, 999999]);

        $this->assertCount(1, $shown, 'A missing destination is skipped, not fatal.');
    }

    public function test_with_no_choice_made_the_featured_destinations_stand_in(): void
    {
        $category = DestinationCategory::factory()->create();

        Destination::factory()->create(['name' => 'Featured Cove', 'is_featured' => true, 'category_id' => $category->id]);
        Destination::factory()->create(['name' => 'Ordinary Cove', 'is_featured' => false, 'category_id' => $category->id]);

        $shown = $this->about->destinationsFor([]);

        $this->assertSame(['Featured Cove'], $shown->pluck('name')->all());
    }

    /* The trail ---------------------------------------------------------- */

    public function test_saving_and_publishing_are_both_recorded(): void
    {
        $this->about->saveDraft(Section::Hero, ['heading' => 'New'], $this->office);
        $this->about->publish(Section::Hero, $this->office);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'about.draft_saved',
            'user_id' => $this->office->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'about.published',
            'user_id' => $this->office->id,
        ]);
    }
}
