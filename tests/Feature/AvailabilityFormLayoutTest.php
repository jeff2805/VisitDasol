<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The availability search on a resort page: six controls, two of which
 * carry a helper line.
 *
 * WHY A TEST FOR A LAYOUT. The form was a .row.g-3.align-items-end, and
 * align-items: flex-end bottom-aligns every column in a flex line. Two of
 * the six fields have a "Resort's usual check-in/out" line under them and
 * four do not, so the four without were pushed down by exactly the height
 * of that line to make their bottom edges meet it. Every row was staggered
 * and the submit button lined up with a helper caption instead of the
 * Guests input beside it.
 *
 * Nothing failed and nothing warned; it just quietly stopped being a row.
 * A child count and a track count are two numbers in two files that have to
 * agree, which is the sort of thing a person does not notice and a test
 * does — the same reason StaySearchLayoutTest exists.
 */
class AvailabilityFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($owner, 'owner')->active()->create();
        Room::factory()->for($this->resort)->create(['total_rooms' => 2, 'capacity' => 4]);
    }

    /** The stylesheet with its comments stripped, so prose cannot match. */
    private function liveCss(): string
    {
        return preg_replace(
            '#/\*.*?\*/#s',
            '',
            file_get_contents(public_path('css/visitdasol.css'))
        );
    }

    /** Just the availability form, out of the resort page. */
    private function form(): string
    {
        $html = $this->get(route('resorts.show', $this->resort))->assertOk()->getContent();

        preg_match('/<form[^>]*class="vd-availability-form[^"]*"[^>]*>.*?<\/form>/s', $html, $m);

        if (($m[0] ?? '') === '') {
            // The class is not required on the <form>; fall back to the grid.
            preg_match('/<div class="vd-availability">.*?<\/div>\s*<\/form>/s', $html, $m);
        }

        $this->assertNotSame('', $m[0] ?? '', 'The availability form was not found on the page.');

        return $m[0];
    }

    /* The markup ----------------------------------------------------------- */

    /**
     * THE RULE THAT BROKE IT. A flex row that bottom-aligns its columns
     * cannot hold fields of two different heights without staggering them.
     */
    public function test_the_form_no_longer_bottom_aligns_a_flex_row(): void
    {
        $form = $this->form();

        $this->assertStringNotContainsString('align-items-end', $form,
            'Bottom-aligning the row is what pushed the helper-less fields down.');

        $this->assertStringNotContainsString('row g-3', $form,
            'The form is laid out by .vd-availability now, not by a Bootstrap row.');

        $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bcol-(sm|md|lg|xl)?-?\d/', $form,
            'No Bootstrap column should remain inside the form.');
    }

    /** Every control is still there, and still in its own cell. */
    public function test_every_control_is_present_in_a_cell_of_its_own(): void
    {
        $form = $this->form();

        foreach (['check_in', 'check_in_time', 'check_out', 'check_out_time', 'guests'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form, "The form lost its {$field} field.");
            $this->assertStringContainsString('for="'.$field.'"', $form, "The {$field} field lost its label.");
        }

        $this->assertStringContainsString('Check Availability', $form);

        // Five fields, plus the button's cell.
        $this->assertSame(
            6,
            preg_match_all('/class="vd-availability__field/', $form),
            'The grid should have six children: five fields and the button.'
        );
    }

    /**
     * THE HELPER TEXT HAS TO BE INSIDE A CELL.
     *
     * Loose in the row it is a sibling of the fields and changes the line
     * height for all of them, which is the whole of the original bug.
     */
    public function test_each_helper_line_sits_inside_its_own_field(): void
    {
        $form = $this->form();

        $this->assertSame(2, preg_match_all('/vd-availability__hint/', $form),
            'Both "Resort\'s usual ..." lines should carry the hint class.');

        /*
         * Split the grid on its cell boundaries and look at each cell on
         * its own. A hint has to share a cell with the input it describes;
         * loose in the grid it would be a seventh child and would change
         * the row height for every field beside it, which is the bug.
         */
        $cells = preg_split('/(?=<div class="vd-availability__field)/', $form);
        $cells = array_values(array_filter($cells, fn ($cell) => str_contains($cell, 'vd-availability__field')));

        $this->assertCount(6, $cells);

        $hinted = [];

        foreach ($cells as $cell) {
            $hints = preg_match_all('/vd-availability__hint/', $cell);

            if ($hints === 0) {
                continue;
            }

            $this->assertSame(1, $hints, 'A cell should carry at most one hint.');

            preg_match('/name="([a-z_]+)"/', $cell, $named);
            $hinted[] = $named[1] ?? '';
        }

        // Exactly the two time fields, and nothing else.
        $this->assertSame(['check_in_time', 'check_out_time'], $hinted);

        // And nothing is bare Bootstrap muted text loose in the grid.
        $this->assertStringNotContainsString('<small class="text-muted-vd">', $form);
    }

    /** The button has no label, so it needs the cell that offsets it. */
    public function test_the_button_cell_is_marked_so_it_can_be_aligned(): void
    {
        $form = $this->form();

        $this->assertStringContainsString('vd-availability__go', $form);
        $this->assertStringNotContainsString('d-grid', $form,
            'The button is sized by .vd-availability__go .btn now.');
    }

    /* The stylesheet ------------------------------------------------------- */

    public function test_the_grid_starts_its_cells_at_the_top(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-availability\s*\{[^}]*align-items:\s*start/s',
            $css,
            'align-items: start is what stops the helper line dragging its neighbours down.'
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-availability\s*\{[^}]*display:\s*grid/s',
            $css
        );
    }

    /**
     * A native date input reports an intrinsic width of about 140px, and a
     * grid item will not shrink below its content without this. Leaving it
     * out is how the form pushes out of its panel on a phone.
     */
    public function test_the_cells_may_shrink_below_their_content(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.vd-availability__field\s*\{[^}]*min-width:\s*0/s',
            $this->liveCss()
        );
    }

    /** Every control is one height, so a row is not four different boxes. */
    public function test_the_controls_share_one_height(): void
    {
        $css = $this->liveCss();

        $this->assertMatchesRegularExpression(
            '/\.vd-availability\s+\.form-control\s*\{[^}]*min-height:\s*var\(--vd-availability-control\)/s',
            $css
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-availability__go\s+\.btn\s*\{[^}]*min-height:\s*var\(--vd-availability-control\)/s',
            $css
        );
    }

    /**
     * THE COUNT THAT HAS TO AGREE WITH THE MARKUP.
     *
     * Six children over three tracks is two clean rows. Any other track
     * count leaves an orphan on a line of its own.
     */
    public function test_the_desktop_track_count_divides_the_children(): void
    {
        $children = preg_match_all('/class="vd-availability__field/', $this->form());

        preg_match_all(
            '/\.vd-availability\s*\{[^}]*?grid-template-columns:\s*([^;]+);/s',
            $this->liveCss(),
            $rules
        );

        $this->assertNotEmpty($rules[1], 'No grid columns are declared for the form.');

        $widest = trim(end($rules[1]));

        $this->assertMatchesRegularExpression('/repeat\(\s*3\s*,/', $widest,
            'The widest breakpoint should put three across. Declared: '.$widest);

        $this->assertSame(0, $children % 3,
            $children.' controls over three columns leaves one stranded on its own row.');
    }

    /** Stacked, then two, then three. */
    public function test_it_is_laid_out_at_every_width(): void
    {
        $css = $this->liveCss();

        // Mobile: one column, declared on the bare class.
        $this->assertMatchesRegularExpression(
            '/\.vd-availability\s*\{[^}]*grid-template-columns:\s*1fr/s',
            $css
        );

        foreach (['576px', '992px'] as $breakpoint) {
            $this->assertMatchesRegularExpression(
                '/@media \(min-width: '.preg_quote($breakpoint, '/').'\)\s*\{\s*\.vd-availability\s*\{/s',
                $css,
                "The {$breakpoint} breakpoint should lay the form out."
            );
        }

        /*
         * The button's offset belongs to the widths where it shares a row
         * with a labelled field. Stacked, it would be a gap for nothing.
         */
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 576px\).*?\.vd-availability__go::before\s*\{[^}]*height:\s*var\(--vd-availability-label\)/s',
            $css
        );
    }

    /* The form still works -------------------------------------------------- */

    /**
     * Laying it out again must not have touched what it does. The same
     * query the old markup sent still comes back with an answer.
     */
    public function test_the_search_still_returns_availability(): void
    {
        $this->travelTo('2030-06-01 09:00:00');

        $this->get(route('resorts.show', [
            $this->resort,
            'check_in' => '2030-10-10',
            'check_out' => '2030-10-13',
            'guests' => 2,
        ]))->assertOk()->assertSee('2 rooms free');
    }

    /** An impossible range is still explained rather than silently ignored. */
    public function test_the_date_validation_still_runs(): void
    {
        $this->travelTo('2030-06-01 09:00:00');

        $this->get(route('resorts.show', [
            $this->resort,
            'check_in' => '2030-10-13',
            'check_out' => '2030-10-10',
        ]))->assertOk()->assertSee('The check-out date must be after the check-in date.');
    }

    /**
     * app.js drags the check-out minimum after the check-in date, and hangs
     * that off these two attributes. They have to stay, and stay in the same
     * form: the script reads checkIn.form and looks for its partner inside.
     */
    public function test_the_javascript_hooks_survived_the_relayout(): void
    {
        $form = $this->form();

        $this->assertStringContainsString('data-stay-check-in', $form);
        $this->assertStringContainsString('data-stay-check-out', $form);
    }

    /** The values the visitor already chose are still written back. */
    public function test_the_chosen_values_are_still_preserved(): void
    {
        $this->travelTo('2030-06-01 09:00:00');

        $html = $this->get(route('resorts.show', [
            $this->resort,
            'check_in' => '2030-10-10',
            'check_out' => '2030-10-13',
            'check_in_time' => '15:30',
            'check_out_time' => '10:00',
            'guests' => 3,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="check_in"[^>]*value="2030-10-10"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_out"[^>]*value="2030-10-13"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_in_time"[^>]*value="15:30"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_out_time"[^>]*value="10:00"/s', $html);
        $this->assertMatchesRegularExpression('/name="guests"[^>]*value="3"/s', $html);
    }
}
