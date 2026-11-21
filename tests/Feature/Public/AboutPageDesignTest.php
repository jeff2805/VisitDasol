<?php

namespace Tests\Feature\Public;

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
 * The redesigned public About page.
 *
 * The management tests prove the office can write it. These prove the page
 * DRAWS what they wrote — every section, in the shell the design asks for —
 * and, just as importantly, that the parts which are not theirs are still
 * there and still fixed.
 */
class AboutPageDesignTest extends TestCase
{
    use RefreshDatabase;

    private AboutPageService $about;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->about = app(AboutPageService::class);
        $this->office = User::factory()->superAdmin()->create();
    }

    private function publish(Section $section, array $payload): void
    {
        $this->about->saveDraft($section, $payload, $this->office);
        $this->about->publish($section, $this->office);
    }

    /* Every section is drawn --------------------------------------------- */

    public function test_the_page_draws_all_seven_sections(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('ABOUT DASOL')
            ->assertSee('Discover Dasol, Pangasinan')
            ->assertSee('The municipality')
            ->assertSee('History of Dasol')
            ->assertSee("Dasol's Salt-Making Heritage")
            ->assertSee('Explore the Beauty of Dasol')
            ->assertSee('Where Dasol is')
            ->assertSee('Own a resort in Dasol?');
    }

    public function test_the_hero_shows_what_the_office_published(): void
    {
        $this->publish(Section::Hero, [
            'label' => 'VISIT THE SALT CAPITAL',
            'heading' => 'Discover Dasol',
            'subheading' => 'Beaches, salt beds and quiet coves.',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('VISIT THE SALT CAPITAL')
            ->assertSee('Discover Dasol')
            ->assertSee('Beaches, salt beds and quiet coves.');
    }

    public function test_an_uploaded_hero_picture_becomes_the_banner(): void
    {
        $this->about->saveDraft(Section::Hero, [
            'image_path' => $this->about->storeImage(
                Section::Hero,
                UploadedFile::fake()->image('shore.jpg', 1600, 600)
            ),
        ], $this->office);
        $this->about->publish(Section::Hero, $this->office);

        $path = $this->about->published()['hero']['image_path'];

        $this->get(route('about'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($path), false);
    }

    public function test_the_heritage_gallery_shows_its_pictures_and_captions(): void
    {
        $first = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('beds.jpg', 800, 600));
        $second = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('rake.jpg', 800, 600));

        $this->publish(Section::Heritage, [
            'heading' => 'Salt-making',
            'body' => 'Made by hand.',
            'images' => [
                ['path' => $first, 'caption' => 'Salt beds at sunset'],
                ['path' => $second, 'caption' => null],
            ],
        ]);

        $page = $this->get(route('about'))->assertOk();

        $page->assertSee('Salt beds at sunset');
        $page->assertSee(Storage::disk('public')->url($first), false);
        $page->assertSee(Storage::disk('public')->url($second), false);
    }

    public function test_history_paragraphs_are_drawn_in_order(): void
    {
        $this->publish(Section::History, [
            'heading' => 'The story',
            'paragraphs' => ['First came the salt.', 'Then came the visitors.'],
        ]);

        $html = $this->get(route('about'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Then came the visitors.'),
            strpos($html, 'First came the salt.'),
            'The paragraphs are out of order.'
        );
    }

    public function test_the_location_card_shows_what_the_office_set(): void
    {
        $this->publish(Section::Location, [
            'heading' => 'Find us',
            'municipality' => 'Dasol',
            'province' => 'Pangasinan',
            'region' => 'Ilocos Region (Region I)',
            'country' => 'Philippines',
            'postal_code' => '2411',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Find us')
            ->assertSee('Dasol, Pangasinan, Philippines')
            ->assertSee('2411');
    }

    /* Destinations are the real records ---------------------------------- */

    public function test_the_destination_cards_link_to_the_real_destinations(): void
    {
        $category = DestinationCategory::factory()->create();
        $beach = Destination::factory()->create([
            'name' => 'Tambobong Beach',
            'category_id' => $category->id,
        ]);

        $this->publish(Section::Destinations, [
            'heading' => 'Explore the Beauty of Dasol',
            'destination_ids' => [$beach->id],
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Tambobong Beach')
            ->assertSee(route('destinations.show', $beach));
    }

    /* What the office may not touch -------------------------------------- */

    public function test_how_the_site_works_is_still_on_the_page_and_still_fixed(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('How this site works')
            ->assertSee('reviewed by the Municipal Tourism Office', false);

        // Seven sections, and neither of those two among them.
        $this->assertCount(7, Section::cases());
        $this->assertNotContains('how_it_works', Section::values());
    }

    /**
     * A mistyped address on that card would break registration for every
     * prospective resort owner, so it is not anybody's to type.
     */
    public function test_the_resort_owner_button_always_points_at_registration(): void
    {
        $this->publish(Section::OwnerCta, [
            'visible' => true,
            'heading' => 'Own a resort?',
            'body' => 'Apply here.',
            'button_label' => 'Start my application',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Start my application')
            ->assertSee(route('register.owner'));
    }

    public function test_the_owner_card_can_be_switched_off(): void
    {
        $this->publish(Section::OwnerCta, [
            'visible' => false,
            'heading' => 'Own a resort in Dasol?',
            'body' => 'Hidden for now.',
            'button_label' => 'Apply',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('Hidden for now.');
    }

    /* The shell ----------------------------------------------------------- */

    public function test_the_page_carries_its_own_stylesheet(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('css/about.css', false);
    }

    public function test_a_visitor_never_sees_the_preview_banner(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('this shows your drafts');
    }

    public function test_the_office_preview_is_marked_as_one(): void
    {
        $this->actingAs($this->office)
            ->get(route('admin.about.preview'))
            ->assertOk()
            ->assertSee('this shows your drafts');
    }

    /**
     * The office types prose into those boxes, not markup.
     */
    public function test_nothing_the_office_types_is_treated_as_markup(): void
    {
        $this->publish(Section::Municipality, [
            'heading' => 'The municipality',
            'body' => 'Salt beds cover <script>alert(1)</script> hectares.',
        ]);

        $html = $this->get(route('about'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
