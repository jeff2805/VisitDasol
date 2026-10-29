<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The stay search bar: "where, when, how many", on one row.
 *
 * WHY A TEST FOR A GRID. The desktop rule read
 *   grid-template-columns: 1.6fr 1fr 1fr .7fr auto;
 * — five tracks — while the form had seven children. The two time
 * fields had been added to the Blade and nobody came back to the
 * stylesheet, so CSS Grid did the only thing it could and wrapped
 * Guests and Search onto a second row. Nothing failed, nothing warned;
 * it just quietly stopped being one search bar.
 *
 * A column count and a child count are two numbers in two files that
 * have to agree, which is precisely the sort of thing a person will not
 * notice and a test will.
 */
class StaySearchLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function stylesheet(): string
    {
        return file_get_contents(public_path('css/visitdasol.css'));
    }

    /** The stylesheet with its comments taken out, so prose cannot match. */
    private function liveCss(): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $this->stylesheet());
    }

    private function aListing(): User
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        Room::factory()->for($resort)->create();

        return User::factory()->create();
    }

    /* The form itself ------------------------------------------------------ */

    public static function pages(): array
    {
        return [
            'public home' => ['/', false],
            'tourist home' => ['/tourist/dashboard', true],
            'resort list' => ['/resorts', false],
        ];
    }

    /**
     * Every page that carries the search must carry the same search:
     * same route, same six parameters, same JavaScript hooks.
     */
    #[DataProvider('pages')]
    public function test_the_search_keeps_its_route_and_every_field(string $path, bool $signedIn): void
    {
        $tourist = $this->aListing();

        $response = $signedIn
            ? $this->actingAs($tourist)->get($path)
            : $this->get($path);

        $response->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form class="vd-stay-search"[^>]*method="GET"/',
            $html,
            'The search must stay a GET form.'
        );

        preg_match('/<form class="vd-stay-search".*?<\/form>/s', $html, $m);
        $form = $m[0] ?? '';

        $this->assertStringContainsString(route('resorts.index'), $form);

        // The query parameters the resort listing reads back.
        foreach (['barangay', 'check_in', 'check_in_time', 'check_out', 'check_out_time', 'guests'] as $field) {
            $this->assertStringContainsString(
                'name="'.$field.'"',
                $form,
                "The search lost its {$field} field."
            );
        }

        /*
         * app.js hangs the check-out minimum off these two. Without them
         * a guest can ask to leave before they arrive — which is how it
         * behaved for real visitors once already, when the same code was
         * an inline <script> the Content Security Policy refused to run.
         */
        $this->assertStringContainsString('data-stay-check-in', $form);
        $this->assertStringContainsString('data-stay-check-out', $form);
    }

    /* The layout ----------------------------------------------------------- */

    /**
     * THE COUNT THAT WENT WRONG.
     *
     * However many children the grid has, the widest breakpoint must
     * name that many tracks, or the last ones wrap to a second row.
     */
    public function test_the_widest_grid_has_a_column_for_every_control(): void
    {
        $this->aListing();

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<form class="vd-stay-search".*?<\/form>/s', $html, $m);

        $children = preg_match_all(
            '/<div class="vd-stay-search__(?:field|go)/',
            $m[0] ?? ''
        );

        $this->assertSame(7, $children, 'The form should have seven grid children.');

        // Every grid-template-columns declared for .vd-stay-search.
        preg_match_all(
            '/\.vd-stay-search\s*\{[^}]*?grid-template-columns:\s*([^;]+);/s',
            $this->liveCss(),
            $rules
        );

        $this->assertNotEmpty($rules[1], 'No grid columns are declared for the search.');

        $widest = trim(end($rules[1]));
        $tracks = preg_split('/\s+/', $widest);

        $this->assertCount(
            $children,
            $tracks,
            'The widest breakpoint declares '.count($tracks).' columns for '
            .$children.' controls, so the last ones wrap onto a second row. '
            .'Declared: '.$widest
        );
    }

    /**
     * The single row is asked for at 1200px, not 992px.
     *
     * Between those two the container is 960px wide. A native date
     * input will not render much below 140px and a time input below
     * 110px, so seven controls there would overflow or clip their own
     * pickers. Three clean columns is the honest answer at that width.
     */
    public function test_the_single_row_waits_for_room_to_put_it_in(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1200px\)\s*\{\s*\.vd-stay-search\s*\{[^}]*grid-template-columns/s',
            $css,
            'The seven-column row should be declared at the 1200px breakpoint.'
        );

        // And smaller screens still get a grid rather than a scrollbar.
        foreach (['576px', '768px'] as $breakpoint) {
            $this->assertMatchesRegularExpression(
                '/@media \(min-width: '.preg_quote($breakpoint, '/').'\)\s*\{\s*\.vd-stay-search\s*\{/s',
                $css,
                "The {$breakpoint} breakpoint should lay the search out too."
            );
        }
    }

    /**
     * Nothing may cap the panel narrower than the row needs.
     *
     * .vd-hero .vd-stay-search was max-width: 58rem — 928px — so the
     * seven-column row could never have formed on the public home
     * however wide the window was.
     */
    public function test_no_rule_caps_the_panel_below_the_width_the_row_needs(): void
    {
        preg_match_all(
            '/\.vd-stay-search[^{]*\{[^}]*max-width:\s*([^;]+);/s',
            $this->liveCss(),
            $caps
        );

        /*
         * A fixed rem ceiling is the only kind that can starve the row;
         * a percentage or a min() still gives way to the container.
         * Asserted as a list so this says something whether or not any
         * cap exists — right now none does, and that is the finding.
         */
        $tooNarrow = [];

        foreach ($caps[1] as $cap) {
            $cap = trim($cap);

            if (preg_match('/^([\d.]+)rem$/', $cap, $m) && (float) $m[1] < 68) {
                $tooNarrow[] = $cap;
            }
        }

        $this->assertSame(
            [],
            $tooNarrow,
            'These caps are narrower than seven controls need: '
            .implode(', ', $tooNarrow)
        );
    }

    /** The button stays the gold call to action, not a plain one. */
    public function test_the_search_button_is_the_accent_button(): void
    {
        $this->aListing();

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<form class="vd-stay-search".*?<\/form>/s', $html, $m);

        $this->assertStringContainsString('btn-accent', $m[0] ?? '');
        $this->assertStringContainsString('bi-search', $m[0] ?? '');

        // Mango on bay, which is what btn-accent resolves to.
        $this->assertMatchesRegularExpression(
            '/\.btn-accent\s*\{[^}]*--bs-btn-bg:\s*var\(--vd-mango\)/s',
            $this->liveCss()
        );
    }
}
