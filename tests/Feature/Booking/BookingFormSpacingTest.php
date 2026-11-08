<?php

namespace Tests\Feature\Booking;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The booking form's spacing is tightened, and ONLY the booking form's.
 *
 * Every field on that page comes from <x-form.input>, which wraps itself
 * in Bootstrap's mb-3. Eighteen other forms draw from the same component
 * — the owner's room editor, the auth pages, the office's settings — so a
 * rule written without a scope would silently reflow all of them.
 *
 * That is the hazard this guards. The spacing values themselves are a
 * judgement anybody can retune; the scope is the thing that must not slip.
 */
class BookingFormSpacingTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return file_get_contents(public_path('css/visitdasol.css'));
    }

    /** The stylesheet with comments stripped, so prose cannot match. */
    private function liveCss(): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $this->css());
    }

    private function form(): string
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        $room = Room::factory()->for($resort)->create([
            'capacity' => 4, 'total_rooms' => 2, 'price_per_night' => 1500,
        ]);

        return $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.create', [
                'room' => $room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->getContent();
    }

    public function test_the_form_carries_the_spacing_hook(): void
    {
        $this->assertMatchesRegularExpression(
            '/<form[^>]*data-booking-form/',
            $this->form(),
            'Without the class the scoped rules match nothing.'
        );
    }

    /**
     * THE RULE THAT MATTERS. A spacing override must be scoped to
     * something; a bare .mb-3 in this stylesheet reflows every form on
     * the site, including the eighteen that share <x-form.input>.
     *
     * Which scope is not this test's business — .vd-discount-fields has
     * its own and is perfectly entitled to it. What is forbidden is a
     * selector that is nothing but the utility class.
     */
    public function test_no_spacing_override_escapes_its_own_component(): void
    {
        $css = $this->liveCss();

        // Every declaration block in this file that overrides a spacing utility.
        preg_match_all('/([^{}]+)\{[^}]*margin-bottom:[^}]*!important[^}]*\}/s', $css, $m);

        $unscoped = [];

        foreach ($m[1] as $block) {
            foreach (explode(',', $block) as $selector) {
                $selector = trim(preg_replace('/\s+/', ' ', $selector));

                if (! preg_match('/\.(mb|mt|my|m)-\d/', $selector)) {
                    continue;
                }

                // Scoped means something stands in front of the utility —
                // an ancestor, an attribute, another class on the element.
                $bare = (bool) preg_match('/^\.(mb|mt|my|m)-\d+(:[a-z-]+(\([^)]*\))?)?$/', $selector);

                if ($bare) {
                    $unscoped[] = $selector;
                }
            }
        }

        $this->assertSame([], $unscoped,
            "Spacing overrides with no scope at all:\n".implode("\n", $unscoped));
    }

    /** The tightening is actually declared, and only once. */
    public function test_the_field_gap_is_tightened(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\[data-booking-form\] \.mb-3,[^{]*\{[^}]*margin-bottom:\s*0\.5rem\s*!important/s',
            $css
        );

        /*
         * It was written twice, one block shadowing the other, and
         * neither ran. Two copies of a rule is how they drift: somebody
         * retunes the one they found and the other quietly wins.
         */
        $this->assertSame(
            1,
            preg_match_all('/\[data-booking-form\] \.mb-3,/', $css),
            'The booking-form spacing block is declared more than once.'
        );
    }

    /** Helper text stays with the input above it. */
    public function test_helper_text_stays_close_to_its_input(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-booking-form\] \.form-text,[^{]*\{[^}]*margin-top:\s*3px/s',
            $this->liveCss()
        );
    }

    /* The hole under Children ------------------------------------------------ */

    /** The grid holds the four counts a guest types into, two by two. */
    public function test_the_guest_counts_grid_holds_exactly_four_fields(): void
    {
        preg_match('/<div class="vd-guest-counts">(.*?)\n\s{32}<\/div>/s', $this->form(), $m);
        $grid = $m[1] ?? '';

        $this->assertNotSame('', $grid, 'The guest-counts grid was not found.');

        preg_match_all('/name="([a-z_]+)"/', $grid, $n);

        $this->assertSame(
            ['rooms_booked', 'adults', 'children', 'pwd_guests'],
            $n[1],
            'Total guests moved below Male and Female; these four stay here.'
        );
    }

    /**
     * TWO MECHANISMS WERE SETTING THESE WIDTHS AND THE FIELDS CAME OUT A
     * QUARTER OF THE ROW.
     *
     * The grid children still carried Bootstrap's .col-sm-6, which is
     * `flex: 0 0 auto; width: 50%`. Inside a 1fr track that 50% resolves
     * against the track, not the row — half of a half. The boxes were
     * narrow and "Children (aged 12 and under)" wrapped where there was
     * room for it on one line.
     *
     * The grid is the only thing that may size these columns.
     */
    public function test_no_column_class_fights_the_grid(): void
    {
        preg_match('/<div class="vd-guest-counts">(.*?)\n\s{32}<\/div>/s', $this->form(), $m);
        $grid = $m[1] ?? '';

        $this->assertNotSame('', $grid);

        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bcol-(sm|md|lg|xl)?-?\d/',
            $grid,
            'A Bootstrap column class inside the grid sets width a second time.'
        );

        // And the grid is not also a Bootstrap .row, whose negative
        // margins assume column padding that is no longer there.
        $this->assertStringNotContainsString('class="row vd-guest-counts"', $this->form());
    }

    /** The gutter has to be stated, because the columns' padding went. */
    public function test_the_grid_declares_its_own_gap_and_stacks_on_a_phone(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-guest-counts\s*\{[^}]*grid-template-columns:\s*1fr;/s',
            $css,
            'One column is the phone default.'
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-guest-counts\s*\{[^}]*gap:\s*var\(--vd-space-sm\)\s+var\(--vd-space-xl\)/s',
            $css,
            'Without a gap the two columns would touch.'
        );

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 576px\).*?\.vd-guest-counts\s*\{[^}]*grid-template-columns:\s*repeat\(2,/s',
            $css,
            'Two equal columns from the sm breakpoint up.'
        );
    }

    /** Every control fills its column. */
    public function test_the_inputs_fill_their_column(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-guest-counts input,[^{]*\{[^}]*width:\s*100%/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.vd-guest-counts input,[^{]*\{[^}]*min-width:\s*0/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.vd-guest-counts input,[^{]*\{[^}]*box-sizing:\s*border-box/s',
            $css
        );
    }

    /** Total guests is its own full-width field, not half of a row. */
    public function test_total_guests_is_full_width(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression(
            '/<div class="vd-guest-total[^"]*">\s*<label for="field-guests-total"/s',
            $html,
            'Total guests should be a full-width block, not a col-sm-6.'
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-guest-total\s*\{[^}]*width:\s*100%/s',
            $this->liveCss()
        );
    }

    /**
     * NOTHING IN THE GRID IS PLACED BY POSITION ANY MORE.
     *
     * A :nth-child rule used to pull PWD guests across two rows, so that
     * "Total guests" could rise past PWD's four lines of helper text.
     * Both reasons have gone — the helper was removed and Total guests
     * moved out — and so has the rule. A positional rule is the kind
     * that goes quietly wrong when a field is added, so its absence is
     * worth holding.
     */
    public function test_the_grid_places_nothing_by_position(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 576px\).*?\.vd-guest-counts\s*\{[^}]*display:\s*grid/s',
            $css,
            'The two-column grid should start at the sm breakpoint.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.vd-guest-counts\s*>\s*:nth-child/s',
            $css,
            'Nothing in this grid should depend on a field\'s position.'
        );
    }

    /**
     * The order down the page, which is what was asked for:
     * the four counts, then Male and Female, then Total guests, then
     * Travelling from.
     */
    public function test_the_fields_run_in_the_requested_order(): void
    {
        $html = $this->form();

        preg_match_all(
            '/(?:name|id)="(?:field-)?(rooms_booked|adults|children|pwd_guests|guests_male|guests_female|guests-total|origin)"/',
            $html,
            $m
        );

        $this->assertSame(
            [
                'rooms_booked', 'adults', 'children', 'pwd_guests',
                'guests_male', 'guests_female',
                'guests-total',
                'origin',
            ],
            array_values(array_unique($m[1])),
            'Total guests belongs under Male and Female, above Travelling from.'
        );
    }

    /**
     * Below the breakpoint the rule is off and the fields stack in source
     * order, exactly as they did before. Restructuring the markup into two
     * stacks would have reordered them on a phone; this does not.
     */
    /**
     * MOBILE FIRST, AND THE SECOND COLUMN IS THE THING BEHIND A QUERY.
     *
     * The base rule is deliberately unmediated now — it is the phone
     * layout, a single column, which stacks the four counts in source
     * order. What must stay behind the breakpoint is the rule that makes
     * them two across; at the top level it would put two number boxes
     * side by side on a 360px screen.
     */
    public function test_two_columns_only_appear_when_there_is_room(): void
    {
        $css = $this->liveCss();

        $offset = 0;
        $found = 0;

        while (($at = strpos($css, 'grid-template-columns: repeat(2', $offset)) !== false) {
            $before = substr($css, 0, $at);

            // Is this one of ours?
            $ruleStart = strrpos($before, '{');
            $selector = substr($before, strrpos(substr($before, 0, $ruleStart), '}') + 1);

            if (str_contains($selector, 'vd-guest-counts')) {
                $found++;

                $depth = substr_count($before, '{') - substr_count($before, '}');

                $this->assertGreaterThan(
                    0,
                    $depth,
                    'The two-column rule is at the top level, so two number '
                    .'boxes would sit side by side on a phone.'
                );
            }

            $offset = $at + 1;
        }

        $this->assertSame(1, $found, 'Expected exactly one two-column rule for the grid.');
    }

    /** Male and Female are their own row and stay side by side. */
    public function test_male_and_female_remain_a_row_of_their_own(): void
    {
        $html = $this->form();

        $this->assertMatchesRegularExpression(
            '/<div class="row">(?:(?!<\/div>\s*<\/div>).)*?name="guests_male".*?name="guests_female"/s',
            $html,
            'Male and Female should share one plain row, outside the guest-counts grid.'
        );

        // And that row is not the grid.
        preg_match('/<div class="row vd-guest-counts">(.*?)\n\s{32}<\/div>/s', $html, $m);
        $this->assertStringNotContainsString('guests_male', $m[1] ?? '');
    }

    /* The two helper lines that were taken out -------------------------------- */

    /**
     * Children and PWD guests keep their label and their box, and lose
     * the sentences that sat under them.
     */
    public function test_children_and_pwd_have_no_helper_text(): void
    {
        $html = $this->form();

        // The PWD explanation, in the words it was written in.
        $this->assertStringNotContainsString('have a PWD ID', $html);
        $this->assertStringNotContainsString('do not count them twice', $html);

        // Both helpers were written to by booking-form.js through these
        // hooks; with the elements gone the hooks go with them.
        $this->assertStringNotContainsString('data-booking-child-note', $html);
        $this->assertStringNotContainsString('data-booking-pwd-note', $html);

        // The fields themselves are untouched.
        $this->assertStringContainsString('id="field-children"', $html);
        $this->assertStringContainsString('name="children"', $html);
        $this->assertStringContainsString('id="field-pwd-guests"', $html);
        $this->assertStringContainsString('name="pwd_guests"', $html);
        $this->assertStringContainsString('>PWD guests<', $html);
    }

    /**
     * REMOVING THE ELEMENT MUST NOT BREAK THE LIVE TOTALS.
     *
     * booking-form.js reads both hooks and writes to them, so a missing
     * element would be a null reference and the running price would stop
     * updating as the guest types. It guards each with an `if`, and that
     * guard is now load-bearing.
     */
    public function test_the_script_still_guards_the_hooks_it_no_longer_finds(): void
    {
        $js = file_get_contents(public_path('js/booking-form.js'));

        $this->assertStringContainsString('if (out.childNote)', $js);
        $this->assertStringContainsString('if (out.pwdNote)', $js);
    }

    /** Every other helper on the form stays exactly where it was. */
    public function test_no_other_helper_text_was_touched(): void
    {
        $html = $this->form();

        // Beds, Total guests, and the male/female split.
        $this->assertStringContainsString('data-booking-free', $html);
        $this->assertStringContainsString('data-booking-capacity', $html);
        $this->assertStringContainsString('data-booking-sex', $html);
        $this->assertStringContainsString('per room', $html);

        // The child age range is in the LABEL and is not a helper line.
        $this->assertStringContainsString('data-booking-child-ages', $html);
    }

    /* Nothing about the form itself changed ---------------------------------- */

    public function test_the_two_column_layout_and_every_field_survive(): void
    {
        $html = $this->form();

        // The desktop split.
        $this->assertStringContainsString('col-lg-7', $html);
        $this->assertStringContainsString('col-lg-5', $html);

        // The fields, the token, and the file upload the form needs.
        foreach ([
            'contact_first_name', 'contact_last_name', 'contact_phone',
            'adults', 'children', 'pwd_guests', 'rooms_booked',
            'guests_male', 'guests_female', 'origin', 'id_document', '_token',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "Lost the {$field} field.");
        }

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString(route('tourist.bookings.store'), $html);
    }
}
