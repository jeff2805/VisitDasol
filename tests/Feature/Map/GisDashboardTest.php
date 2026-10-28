<?php

namespace Tests\Feature\Map;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\Review;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard around the map: the search panel, the barangay
 * information panel, and the feature cards.
 *
 * Most of what is asserted here is about what must NOT appear. The
 * design this was built from shows land area, coastline length and a
 * star rating for every resort, and none of those exist in this system.
 * A municipal site that prints a figure nobody measured is worse than
 * one with a shorter card, so the rule is tested rather than trusted.
 */
class GisDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function resort(array $attributes = []): Resort
    {
        return Resort::factory()->active()->create($attributes + [
            'name' => 'Salt Breeze Resort',
            'barangay' => 'Tambobong',
            'latitude' => 15.99,
            'longitude' => 119.88,
        ]);
    }

    /* The shell ---------------------------------------------------------- */

    public function test_the_map_page_has_three_panes(): void
    {
        $this->resort();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('vd-gis__pane--list', false)
            ->assertSee('vd-gis__pane--map', false)
            ->assertSee('vd-gis__pane--info', false);
    }

    public function test_the_dashboard_script_is_loaded_on_the_map_page(): void
    {
        $this->resort();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('js/gis.js', false)
            ->assertSee('data-gis', false);
    }

    /**
     * gis.js is the cabinetwork around one map. Every other page that
     * draws a map — the office map, a resort's own map — has no
     * dashboard, and loading it there would be dead weight.
     */
    public function test_the_dashboard_script_is_not_loaded_elsewhere(): void
    {
        $resort = $this->resort();

        $this->get(route('resorts.show', $resort))
            ->assertOk()
            ->assertDontSee('js/gis.js', false);
    }

    /* Search and filters -------------------------------------------------- */

    public function test_the_sidebar_offers_a_search_box_and_the_four_filters(): void
    {
        $this->resort();

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('Search resorts, beaches, barangays...', false)
            ->assertSee('data-gis-search', false)
            ->assertSee('data-gis-filter="all"', false)
            ->assertSee('data-gis-filter="resort"', false)
            ->assertSee('data-gis-filter="destination"', false)
            ->assertSee('data-gis-filter="barangay"', false);
    }

    /**
     * The filtering happens in the browser, so every row has to carry
     * what it would be filtered by. A row with no barangay on it can
     * never be found by barangay.
     */
    public function test_every_row_carries_what_the_search_matches_on(): void
    {
        $this->resort(['name' => 'Salt Breeze Resort', 'barangay' => 'Tambobong']);

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('data-gis-item="resort"', false)
            ->assertSee('data-gis-barangay="Tambobong"', false)
            ->assertSee('data-gis-name="salt breeze resort tambobong"', false);
    }

    public function test_barangays_are_rows_in_the_list_as_well_as_labels_on_the_map(): void
    {
        $this->resort();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('data-gis-item="barangay"', false)
            ->assertSee('Brgy. Tambobong');
    }

    /* Prices and ratings: only where they are real ------------------------ */

    public function test_a_resort_card_shows_its_cheapest_bookable_room(): void
    {
        $resort = $this->resort();

        Room::factory()->for($resort)->create(['price_per_night' => 4500]);
        Room::factory()->for($resort)->create(['price_per_night' => 2500]);

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('₱2,500', false)
            ->assertSee('/ night');
    }

    /**
     * No rooms published, no price. "From ₱0" would advertise a rate no
     * owner has agreed to.
     */
    public function test_a_resort_with_no_rooms_is_shown_without_a_price(): void
    {
        $this->resort();

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('Salt Breeze Resort');
        $response->assertDontSee('vd-gis__price', false);
    }

    /**
     * A zero score reads as "rated badly", not as "nobody has rated this
     * yet". The two must never look the same.
     */
    public function test_a_resort_with_no_reviews_is_shown_without_stars(): void
    {
        $this->resort();

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('vd-gis__rating', false);
    }

    public function test_a_rating_appears_once_somebody_has_left_one(): void
    {
        $resort = $this->resort();

        // A review hangs from a completed stay, so the stay has to be at
        // this resort or the score lands on somebody else's listing.
        $booking = Booking::factory()->for($resort)->completed()->create();

        Review::factory()->create([
            'booking_id' => $booking->id,
            'resort_id' => $resort->id,
            'user_id' => $booking->user_id,
            'rating' => 5,
        ]);

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('vd-gis__rating', false)
            ->assertSee('(1)');
    }

    /* The barangay panel -------------------------------------------------- */

    public function test_the_information_panel_waits_to_be_told_which_barangay(): void
    {
        $this->resort();

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Choose a barangay on the map or in the list to see what is in it.')
            ->assertSee('data-gis-brgy-panel', false)
            ->assertSee('data-gis-brgy-resorts', false)
            ->assertSee('data-gis-brgy-spots', false);
    }

    /**
     * The design this was built from shows "Total Area 5.62 km²" and
     * "Coastline 3.4 km". Neither figure exists anywhere in this system,
     * and inventing them on a municipal map is the exact failure this
     * project refuses elsewhere for barangay pins.
     */
    public function test_the_panel_invents_no_geography(): void
    {
        $this->resort();

        $response = $this->get(route('map'))->assertOk();

        foreach (['Total Area', 'Coastline', 'km²', 'Population'] as $invented) {
            $response->assertDontSee($invented);
        }
    }

    public function test_the_counts_in_the_list_come_from_real_records(): void
    {
        $this->resort(['barangay' => 'Tambobong']);

        Destination::factory()->create([
            'name' => 'White Sand Cove',
            'barangay' => 'Tambobong',
            'latitude' => 15.98,
            'longitude' => 119.87,
        ]);

        $facts = $this->get(route('map'))->assertOk()->viewData('barangayFacts');

        $this->assertSame(1, $facts['Tambobong']['resorts']);
        $this->assertSame(1, $facts['Tambobong']['destinations']);

        // Every one of the eighteen is answered, zeroes included: "none
        // listed here yet" is itself worth knowing.
        $this->assertCount(18, $facts);
        $this->assertSame(0, $facts['Poblacion']['resorts']);
    }

    /* Boundaries that have not been supplied ------------------------------ */

    /**
     * The GeoJSON is a legal dataset the office has to provide. Until it
     * does, the page says so rather than leaving a visitor to wonder why
     * the map has labels and no shapes.
     */
    public function test_the_page_says_when_the_outlines_are_missing(): void
    {
        $this->resort();

        $this->assertFalse(
            app(\App\Services\BarangayBoundaries::class)->exists(),
            'This test describes the state with no boundary file.'
        );

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Barangay outlines have not been supplied yet');
    }

    public function test_the_page_names_the_barangays_with_no_point(): void
    {
        $this->resort();

        $missing = $this->get(route('map'))->assertOk()->viewData('missingPoints');

        // Whatever is unplaced, the page must name it rather than quietly
        // drawing a shorter map.
        if ($missing !== []) {
            $this->get(route('map'))->assertSee(implode(', ', $missing));
        }

        foreach ($missing as $name) {
            $this->assertContains($name, config('visitdasol.barangays'));
        }
    }

    /* Feature cards -------------------------------------------------------- */

    public function test_the_feature_cards_describe_controls_that_exist(): void
    {
        $this->resort();

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('What this map can do', false)
            ->assertSee('Search and filter')
            ->assertSee('Street map and satellite')
            ->assertSee('Road distance');

        /*
         * The design shows a "Road Network" layer. This project has
         * routing, not a road dataset, and a checkbox for a layer that
         * does not exist is a promise the map cannot keep.
         */
        $response->assertDontSee('Road Network');
    }
}
