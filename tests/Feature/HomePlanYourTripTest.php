<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Plan Your Trip block and the three-fact card, inside the hero.
 *
 * Both were added into the stretch of beach between the hero's wording and
 * the shore curve. The order is the whole point of the change, so the order
 * is what is asserted — by position in the document rather than by the
 * markup merely being present somewhere on the page.
 *
 * The hero had to stop being a fixed 650px box to hold them: it was
 * `height: 650px` with `overflow: hidden`, which would have sliced the foot
 * off the card. That is pinned here too, because a later edit putting the
 * fixed height back would clip the card again without failing anything else.
 */
class HomePlanYourTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        Room::factory()->for($resort)->create(['total_rooms' => 2, 'capacity' => 4]);
    }

    private function home(): string
    {
        return $this->get(route('home'))->assertOk()->getContent();
    }

    /** The hero section on its own, so "inside it" can be asked literally. */
    private function hero(): string
    {
        preg_match('/<section class="vd-hero".*?<\/section>/s', $this->home(), $m);

        $this->assertNotEmpty($m, 'The hero section was not found.');

        return $m[0];
    }

    private function liveCss(): string
    {
        return preg_replace(
            '#/\*.*?\*/#s',
            '',
            file_get_contents(public_path('css/visitdasol.css'))
        );
    }

    /* Order ----------------------------------------------------------------- */

    public function test_the_page_is_in_the_required_order(): void
    {
        $html = $this->home();

        $at = function (string $needle) use ($html): int {
            $position = strpos($html, $needle);
            $this->assertNotFalse($position, "\"{$needle}\" is not on the page.");

            return $position;
        };

        $order = [
            'hero background' => $at('vd-hero'),
            'hero description' => $at('vd-hero__lead'),
            'plan your trip' => $at('vd-plan__title'),
            'plan description' => $at('vd-plan__text'),
            'plan buttons' => $at('vd-plan__actions'),
            'facts card' => $at('vd-trip-facts'),
            'shore curve' => $at('vd-shore'),
            'booking bar' => $at('vd-booking-bar'),
            'discover dasol' => $at('Discover Dasol'),
        ];

        $previous = -1;
        $previousName = 'the start of the page';

        foreach ($order as $name => $position) {
            $this->assertGreaterThan(
                $previous,
                $position,
                "\"{$name}\" should come after \"{$previousName}\"."
            );

            $previous = $position;
            $previousName = $name;
        }
    }

    /** Both new blocks are inside the hero, not after it. */
    public function test_both_blocks_are_inside_the_hero(): void
    {
        $hero = $this->hero();

        $this->assertStringContainsString('vd-plan__title', $hero);
        $this->assertStringContainsString('vd-trip-facts', $hero);
        $this->assertStringContainsString('vd-shore', $hero, 'The curve still closes the hero.');
    }

    /* Plan Your Trip -------------------------------------------------------- */

    public function test_the_heading_and_description_read_as_specified(): void
    {
        $hero = $this->hero();

        $this->assertStringContainsString('Plan Your Trip to', $hero);

        // "Dasol" picked out in the gold accent.
        $this->assertMatchesRegularExpression(
            '/Plan Your Trip to\s*<span class="vd-plan__accent">\s*Dasol\s*<\/span>/s',
            $hero
        );

        foreach ([
            'Discover beautiful beaches',
            'explore tourist attractions',
            'create',
            'perfect getaway in Dasol, Pangasinan.',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $hero);
        }
    }

    /** Both buttons exist, carry an icon, and go to pages that exist. */
    public function test_the_two_buttons_link_to_real_screens(): void
    {
        $hero = $this->hero();

        $this->assertStringContainsString('Plan My Trip', $hero);
        $this->assertStringContainsString('Explore Destinations', $hero);

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('map'), '/').'"[^>]*>\s*<i class="bi bi-map-fill/s',
            $hero
        );

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('destinations.index'), '/').'"[^>]*>\s*<i class="bi bi-compass-fill/s',
            $hero
        );

        // And those pages actually answer.
        $this->get(route('map'))->assertOk();
        $this->get(route('destinations.index'))->assertOk();
    }

    public function test_the_buttons_carry_their_two_colour_treatments(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-plan__btn--gold\s*\{[^}]*background:\s*var\(--vd-mango\)[^}]*color:\s*var\(--vd-bay\)/s',
            $css,
            'Gold ground, dark blue text.'
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-plan__btn--bay\s*\{[^}]*background:\s*var\(--vd-bay\)[^}]*color:\s*#FFFFFF/s',
            $css,
            'Coastal blue ground, white text.'
        );

        // Pill, on both.
        $this->assertMatchesRegularExpression(
            '/\.vd-plan__btn\s*\{[^}]*border-radius:\s*999px/s',
            $css
        );
    }

    /* The three facts -------------------------------------------------------- */

    public function test_the_card_carries_the_three_columns(): void
    {
        $hero = $this->hero();

        $this->assertSame(3, preg_match_all('/class="vd-trip-facts__item"/', $hero));

        foreach ([
            'Best time' => 'December',
            'Climate &amp; weather' => 'Tropical Coastal Climate',
            'How to get there' => 'Via Alaminos or Nearby Coastal Routes',
        ] as $label => $heading) {
            $this->assertStringContainsString($label, $hero, "The {$label} label is missing.");
            $this->assertStringContainsString($heading, $hero, "The {$heading} heading is missing.");
        }

        // An icon per column, each in its own circle.
        $this->assertSame(3, preg_match_all('/class="vd-trip-facts__icon"/', $hero));
    }

    /**
     * NOTHING HERE MAY INVENT A TIMETABLE. The page cannot keep fares or
     * departure times current, and a stale one strands somebody — so the
     * route column sends the reader to the map instead of quoting a journey.
     */
    public function test_the_travel_column_quotes_no_schedule(): void
    {
        $hero = $this->hero();

        preg_match('/<article class="vd-trip-facts__item">(?:(?!<\/article>).)*Alaminos.*?<\/article>/s', $hero, $m);
        $column = $m[0] ?? '';

        $this->assertNotSame('', $column, 'The travel column was not found.');

        // No times, no durations, no fares.
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(hours?|hrs?|minutes?|mins?)\b/i', $column);
        $this->assertDoesNotMatchRegularExpression('/₱\s*\d/', $column);
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,2}:\d{2}\b/', $column);

        // It points at the map, which does know.
        $this->assertStringContainsString(route('map'), $column);
    }

    /** White card, rounded, raised, three across on desktop with separators. */
    public function test_the_card_is_styled_as_a_floating_panel(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-trip-facts\s*\{[^}]*background:\s*#FFFFFF/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.vd-trip-facts\s*\{[^}]*border-radius:\s*var\(--vd-radius-lg\)/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.vd-trip-facts\s*\{[^}]*box-shadow:\s*var\(--vd-elevation-3\)/s',
            $css
        );

        // Three across, with a hairline between them, at desktop only.
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 992px\)\s*\{\s*\.vd-trip-facts\s*\{[^}]*grid-template-columns:\s*repeat\(3,/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.vd-trip-facts__item \+ \.vd-trip-facts__item\s*\{[^}]*border-left:/s',
            $css
        );

        // Circular icon grounds.
        $this->assertMatchesRegularExpression(
            '/\.vd-trip-facts__icon\s*\{[^}]*border-radius:\s*50%/s',
            $css
        );
    }

    /* The hero can hold them ------------------------------------------------- */

    /**
     * The fixed height is what would clip the card. A floor is fine; a
     * ceiling is not.
     */
    public function test_the_hero_grows_instead_of_clipping(): void
    {
        preg_match('/\.vd-hero\s*\{([^}]*)\}/s', $this->liveCss(), $m);
        $rule = $m[1] ?? '';

        $this->assertNotSame('', $rule, 'The .vd-hero rule was not found.');

        $this->assertMatchesRegularExpression('/min-height:\s*650px/', $rule);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!min-)height:\s*650px/',
            $rule,
            'A fixed height with overflow: hidden would cut the foot off the card.'
        );

        // And the foot leaves room for the curve that sits over it.
        $this->assertMatchesRegularExpression('/padding-bottom:\s*calc\(/', $rule);
    }

    /* Nothing that was there has moved ---------------------------------------- */

    public function test_the_existing_hero_and_booking_bar_are_untouched(): void
    {
        $html = $this->home();

        $this->assertStringContainsString('vd-wordmark', $html);
        $this->assertStringContainsString('vd-hero__tagline', $html);
        $this->assertStringContainsString('vd-hero__lead', $html);
        $this->assertStringContainsString('navbar', $html);

        // The booking search, still once, still a GET form to the listing.
        $this->assertSame(1, preg_match_all('/<form class="vd-stay-search"/', $html));
        $this->assertStringContainsString('data-stay-check-in', $html);
        $this->assertStringContainsString('data-stay-check-out', $html);
    }

    /** Headings still nest: h1 wordmark, h2 Plan Your Trip, h3 per column. */
    public function test_the_new_headings_nest_correctly(): void
    {
        $hero = $this->hero();

        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $hero));
        $this->assertMatchesRegularExpression('/<h2 class="vd-plan__title">/', $hero);
        $this->assertSame(3, preg_match_all('/<h3 class="vd-trip-facts__heading">/', $hero));
    }
}
