<?php

namespace Tests\Feature\Map;

use App\Models\Resort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "When I press the location button I should actually be where it puts
 * me."
 *
 * The browser cannot always manage that. A phone outdoors is accurate
 * to a few metres; a laptop is placed by the wifi networks it can see,
 * and in a municipality with few mapped networks that is routinely the
 * wrong barangay. No amount of asking again fixes it.
 *
 * So the map does three things instead of pretending: it holds out for
 * the sharpest fix rather than the first one, it says how good that fix
 * is in the same breath as the answer, and it lets the visitor drag the
 * pin to where they actually are.
 *
 * The browser half cannot be exercised from PHP. What is checked here
 * is that the page carries what the script needs, and that the script
 * still contains the rules — a silent revert of any of them would put
 * the wrong-pin problem straight back.
 */
class LocationAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private function mapJs(): string
    {
        return file_get_contents(public_path('js/map.js'));
    }

    private function geoJs(): string
    {
        return file_get_contents(public_path('js/geo.js'));
    }

    /* What the page has to carry ------------------------------------------ */

    public function test_the_map_carries_the_municipal_bounds(): void
    {
        Resort::factory()->active()->create();

        $bounds = config('visitdasol.map.bounds');

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('data-bounds', false)
            ->assertSee((string) $bounds['south'], false)
            ->assertSee((string) $bounds['north'], false);
    }

    /**
     * The warning belongs before the press, not after it goes wrong.
     * Somebody told in advance reads a misplaced pin correctly the
     * first time.
     */
    public function test_the_page_warns_that_a_laptop_can_be_a_kilometre_out(): void
    {
        Resort::factory()->active()->create();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('A laptop cannot find you precisely.')
            ->assertSee('nearby wi-fi')
            ->assertSee('points at your provider')
            ->assertSee('drag the blue pin to where you really are');
    }

    /* The correction, kept ------------------------------------------------- */

    /**
     * A laptop is wrong the same way every visit — it is placed by the
     * networks around it, and those do not move. Without keeping the
     * correction, the same drag is needed every time.
     */
    public function test_a_corrected_pin_is_remembered_in_this_browser(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString("SAVED_PLACE_KEY = 'vd.map.myplace'", $source);
        $this->assertStringContainsString('rememberPlace(moved.lat, moved.lng)', $source);

        // Storage can throw outright in a private window, and a map that
        // dies on that is worse than one that forgets.
        $this->assertMatchesRegularExpression(
            '/function rememberPlace.*?try \{.*?catch/s',
            $source,
            'Saving a spot must survive storage being unavailable.'
        );

        $this->assertMatchesRegularExpression(
            '/function rememberedPlace.*?try \{.*?catch/s',
            $source,
            'Reading a saved spot must survive storage being unavailable.'
        );
    }

    /**
     * Offered, never applied on its own. Where somebody stood last
     * month is not where they are standing now, and silently reusing it
     * would be the wrong-pin problem all over again.
     */
    public function test_a_saved_spot_is_offered_rather_than_assumed(): void
    {
        Resort::factory()->active()->create();

        $source = $this->mapJs();

        // The button exists, starts hidden, and is only revealed once
        // something has actually been saved.
        $this->get(route('map'))
            ->assertOk()
            ->assertSee('data-map-saved-place', false)
            ->assertSee('Use the spot I set')
            ->assertSee('data-map-forget-place', false)
            ->assertSee('Forget that spot');

        $this->assertStringContainsString('button.hidden = !saved', $source);

        // Nothing may place the visitor from storage without a press.
        $this->assertDoesNotMatchRegularExpression(
            '/showSavedPlaceButton\(\);\s*placeMe\(/',
            $source,
            'A saved spot must never be applied automatically on load.'
        );
    }

    /**
     * Past a couple of kilometres the browser is not measuring
     * anything — it has fallen back to the internet connection, which
     * points at the provider. Saying so is the difference between "try
     * again" and "stop trying and drag the pin".
     */
    public function test_an_internet_based_guess_is_named_as_one(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString('best.coords.accuracy >= 2000', $source);
        $this->assertStringContainsString('has no GPS fix', $source);
        $this->assertStringContainsString('points at your provider, not at you', $source);
    }

    /* The rules the script must keep -------------------------------------- */

    /**
     * A cached fix is how somebody who has driven across town is shown
     * where they had breakfast.
     */
    public function test_no_map_asks_for_a_stale_position(): void
    {
        foreach (['map.js', 'location-map.js'] as $file) {
            $source = file_get_contents(public_path('js/'.$file));

            $this->assertStringNotContainsString(
                'maximumAge: 60000',
                $source,
                "{$file} accepts a cached position again."
            );

            $this->assertStringContainsString(
                'maximumAge: 0',
                $source,
                "{$file} should insist on a fresh fix."
            );
        }
    }

    /**
     * watchPosition, not getCurrentPosition: the first reading a phone
     * gives is the cheap one, and the GPS fix arrives seconds later.
     */
    public function test_both_maps_wait_for_the_sharpest_fix(): void
    {
        foreach (['map.js', 'location-map.js'] as $file) {
            $source = file_get_contents(public_path('js/'.$file));

            $this->assertStringContainsString(
                'watchPosition',
                $source,
                "{$file} takes the first fix instead of the best one."
            );

            $this->assertStringContainsString(
                'position.coords.accuracy < best.coords.accuracy',
                $source,
                "{$file} no longer keeps the sharpest reading."
            );
        }
    }

    /**
     * A position the visitor cannot argue with is the whole complaint.
     * The pin is draggable and every distance follows it.
     */
    public function test_the_visitor_can_correct_the_pin(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString('draggable: true', $source);
        $this->assertStringContainsString("hereMarker.on('dragend'", $source);

        // Dragging has to re-measure, or the pin moves and the figures
        // beside it quietly go on describing the old spot.
        $this->assertMatchesRegularExpression(
            '/dragend.*?placeMe\(.*?loadRoadDistances\(\)/s',
            $source,
            'Dragging the pin must re-measure the distances.'
        );
    }

    /**
     * A pin the visitor placed has no margin of error, so it gets no
     * ring. Drawing one would invent a measurement.
     */
    public function test_a_hand_placed_pin_claims_no_accuracy(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString('placeMe(moved.lat, moved.lng, null, true)', $source);
        $this->assertStringContainsString('You placed this yourself', $source);
    }

    /* Saying how good the fix is ------------------------------------------- */

    public function test_accuracy_is_graded_rather_than_just_printed(): void
    {
        $source = $this->geoJs();

        $this->assertStringContainsString('describeAccuracy', $source);

        // The three grades the readouts branch on.
        foreach (["'good'", "'fair'", "'poor'", "'unknown'"] as $grade) {
            $this->assertStringContainsString($grade, $source);
        }

        $this->assertStringContainsString('withinBounds', $source);
    }

    /**
     * "Found you" over a reading two kilometres wide is the sentence
     * that makes somebody believe a wrong pin.
     */
    public function test_a_poor_fix_is_not_announced_as_success(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString('Found a rough position', $source);
        $this->assertStringContainsString('wide enough to be the wrong barangay', $source);

        // And a position outside the municipality is called out.
        $this->assertStringContainsString('places you outside Dasol', $source);
    }

    /**
     * Centred on the visitor, not fitted to a resort that may be
     * kilometres away — at that zoom a reading a kilometre wrong looks
     * perfectly reasonable.
     */
    public function test_the_map_zooms_to_the_person_not_to_a_distant_pin(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString('hereCircle.getBounds()', $source);
        $this->assertStringContainsString('maxZoom: 17', $source);
    }

    /* The two files must not drift apart ----------------------------------- */

    public function test_both_maps_agree_on_what_a_good_fix_is(): void
    {
        foreach (['map.js', 'location-map.js'] as $file) {
            $source = file_get_contents(public_path('js/'.$file));

            $this->assertStringContainsString('GOOD_ENOUGH_METRES = 40', $source, $file);
            $this->assertStringContainsString('PATIENCE_MS = 9000', $source, $file);
        }
    }
}
