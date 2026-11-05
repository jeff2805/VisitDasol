<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the stay search stands on the home page, and that moving it did
 * nothing to what it does.
 *
 * IT USED TO SIT INSIDE THE HERO, which is a fixed 650px tall with
 * overflow: hidden, pushed down into that box by a `margin-top: 4rem
 * !important` written for that one spot. On a short window the foot of the
 * card met the bottom of the hero and was cut off by the overflow.
 *
 * It now has a band of its own between the hero and "Discover Dasol". The
 * order is the point, so the order is what is asserted — by position in the
 * document, not by the presence of the markup, because both were true
 * before as well.
 */
class HomeBookingBarTest extends TestCase
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

    /** The stylesheet with comments stripped, so prose cannot match. */
    private function liveCss(): string
    {
        return preg_replace(
            '#/\*.*?\*/#s',
            '',
            file_get_contents(public_path('css/visitdasol.css'))
        );
    }

    /* Where it stands ------------------------------------------------------ */

    public function test_the_search_sits_between_the_hero_and_discover_dasol(): void
    {
        $html = $this->home();

        $hero = strpos($html, 'vd-hero');
        $bar = strpos($html, 'vd-booking-bar');
        $search = strpos($html, 'vd-stay-search');
        $discover = strpos($html, 'Discover Dasol');

        $this->assertNotFalse($hero, 'The hero is missing.');
        $this->assertNotFalse($bar, 'The booking bar has no band of its own.');
        $this->assertNotFalse($search, 'The stay search is missing.');
        $this->assertNotFalse($discover, 'The Discover Dasol heading is missing.');

        $this->assertLessThan($bar, $hero, 'The bar must come after the hero.');
        $this->assertLessThan($search, $bar, 'The search must be inside its band.');
        $this->assertLessThan($discover, $search, 'The search must come before Discover Dasol.');
    }

    /** It is no longer a child of the hero section. */
    public function test_the_search_is_outside_the_hero(): void
    {
        $html = $this->home();

        preg_match('/<section class="vd-hero".*?<\/section>/s', $html, $m);

        $this->assertNotEmpty($m, 'The hero section was not found.');
        $this->assertStringNotContainsString('vd-stay-search', $m[0],
            'The search is still inside the fixed-height, overflow-hidden hero.');
    }

    /** Once on the page, not twice. */
    public function test_the_search_appears_exactly_once(): void
    {
        $html = $this->home();

        $this->assertSame(1, preg_match_all('/<form class="vd-stay-search"/', $html));
        $this->assertSame(1, preg_match_all('/class="vd-booking-bar"/', $html));
    }

    /** Aligned with the rest of the page, not edge to edge. */
    public function test_the_band_uses_the_page_container(): void
    {
        $this->assertMatchesRegularExpression(
            '/<section class="vd-booking-bar"[^>]*>\s*<div class="container">/s',
            $this->home()
        );
    }

    /* The sections it moved between are untouched --------------------------- */

    public function test_the_hero_and_discover_dasol_are_intact(): void
    {
        $html = $this->home();

        // Hero: wordmark, tagline, lead, shore curve.
        $this->assertStringContainsString('vd-wordmark', $html);
        $this->assertStringContainsString('vd-hero__tagline', $html);
        $this->assertStringContainsString('vd-hero__lead', $html);

        // Discover Dasol, its heading and its call to action.
        $this->assertStringContainsString('Discover Dasol', $html);
        $this->assertStringContainsString('Places to stay', $html);
        $this->assertStringContainsString('View all resorts', $html);

        // The navigation bar is the layout's and must be undisturbed.
        $this->assertStringContainsString('navbar', $html);
    }

    /* The card's own layout ------------------------------------------------- */

    /**
     * THE COUNT THAT HAD DRIFTED. The widest breakpoint declared seven
     * tracks while the form carries five children, so two tracks stood
     * empty and the four in use were sized for a barangay select and a
     * guests box that are no longer in the markup.
     */
    public function test_the_widest_breakpoint_has_one_track_per_control(): void
    {
        preg_match('/<form class="vd-stay-search".*?<\/form>/s', $this->home(), $m);

        $children = preg_match_all('/<div class="vd-stay-search__(?:field|go)/', $m[0] ?? '');

        $this->assertSame(5, $children, 'Four fields and the button.');

        preg_match_all(
            '/\.vd-stay-search\s*\{[^}]*?grid-template-columns:\s*([^;]+);/s',
            $this->liveCss(),
            $rules
        );

        $this->assertNotEmpty($rules[1]);

        $tracks = preg_split('/\s+/', trim(end($rules[1])));

        $this->assertCount(
            $children,
            $tracks,
            'The widest breakpoint declares '.count($tracks).' columns for '
            .$children.' controls. Declared: '.trim(end($rules[1]))
        );
    }

    /** The hero-only push went with it. */
    public function test_the_card_no_longer_carries_the_hero_margin(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.vd-stay-search\s*\{[^}]*margin-top:\s*4rem/s',
            $this->liveCss(),
            'margin-top: 4rem !important was written for the inside of the hero.'
        );
    }

    /** Spacing above Discover Dasol, and none wasted below the card. */
    public function test_the_band_is_spaced_without_padding_twice(): void
    {
        $css = $this->liveCss();

        /*
         * Top padding only — the tinted section below brings its own, and a
         * bottom here would stack the two. The calc() carries the band's
         * clamp plus the deliberate 40px drop; both halves are asserted so
         * neither can be lost while the other survives.
         */
        $this->assertMatchesRegularExpression(
            '/\.vd-booking-bar\s*\{[^}]*padding:\s*calc\(\s*clamp\([^)]*\)\s*\+\s*2\.5rem\s*\)\s+0\s+0/s',
            $css,
            'The band should be clamp + 2.5rem on top, and nothing underneath.'
        );

        // Nothing is pulled up over the hero or positioned out of flow.
        preg_match('/\.vd-booking-bar\s*\{([^}]*)\}/s', $css, $m);
        $this->assertStringNotContainsString('position: absolute', $m[1] ?? '');
        $this->assertDoesNotMatchRegularExpression('/margin[^:]*:\s*-/', $m[1] ?? '');
    }

    /** Stacked, then two, then four, then one row. */
    public function test_it_is_laid_out_at_every_width(): void
    {
        $css = $this->liveCss();

        foreach (['576px', '768px', '1200px'] as $breakpoint) {
            $this->assertMatchesRegularExpression(
                '/@media \(min-width: '.preg_quote($breakpoint, '/').'\)\s*\{\s*\.vd-stay-search\s*\{/s',
                $css,
                "The {$breakpoint} breakpoint should lay the card out."
            );
        }

        // Cells may shrink below a date input's intrinsic width, or the card
        // pushes out of its container on a phone.
        $this->assertMatchesRegularExpression(
            '/\.vd-stay-search__field\s*\{[^}]*min-width:\s*0/s',
            $css
        );
    }

    /* Nothing about the form itself changed --------------------------------- */

    public function test_the_form_keeps_its_route_fields_and_hooks(): void
    {
        preg_match('/<form class="vd-stay-search".*?<\/form>/s', $this->home(), $m);
        $form = $m[0] ?? '';

        $this->assertStringContainsString('method="GET"', $form);
        $this->assertStringContainsString(route('resorts.index'), $form);

        foreach (['check_in', 'check_in_time', 'check_out', 'check_out_time'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form, "Lost the {$field} field.");
        }

        // The Search button and its icon.
        $this->assertStringContainsString('btn-accent', $form);
        $this->assertStringContainsString('bi-search', $form);

        // app.js drags the check-out minimum after the check-in date off these.
        $this->assertStringContainsString('data-stay-check-in', $form);
        $this->assertStringContainsString('data-stay-check-out', $form);
    }

    /** The resort's standard hours are still the defaults. */
    public function test_the_default_times_are_unchanged(): void
    {
        $form = $this->home();

        $this->assertMatchesRegularExpression('/name="check_in_time"[^>]*value="14:00"/s', $form);
        $this->assertMatchesRegularExpression('/name="check_out_time"[^>]*value="12:00"/s', $form);
    }

    /** And searching from it still reaches the listing with its answer. */
    public function test_searching_from_the_bar_still_works(): void
    {
        $this->travelTo('2030-06-01 09:00:00');

        $this->get(route('resorts.index', [
            'check_in' => '2030-10-10',
            'check_in_time' => '14:00',
            'check_out' => '2030-10-13',
            'check_out_time' => '12:00',
        ]))->assertOk()->assertSee('2 rooms free for your dates');
    }
}
