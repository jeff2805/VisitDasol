<?php

namespace Tests\Feature\Map;

use App\Enums\UserStatus;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The points the page hands to the browser.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pointsFrom(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->viewData('points'))->map(fn ($point) => (array) $point)->all();
    }

    /* Barangays ----------------------------------------------------------- */

    /**
     * The barangays are what give the map its shape. Without them a visitor
     * sees two pins floating in an unlabelled coastline and cannot tell which
     * part of Dasol they are looking at.
     */
    public function test_the_map_carries_the_barangays_as_well_as_the_listings(): void
    {
        $response = $this->get(route('map'));
        $response->assertOk();

        $barangays = collect($response->viewData('barangays'))->map(fn ($b) => (array) $b);

        $this->assertGreaterThan(0, $barangays->count());

        // Every one that is drawn must be a real barangay of Dasol, and must
        // sit inside the municipal bounds the rest of the system enforces.
        $bounds = config('visitdasol.map.bounds');

        foreach ($barangays as $barangay) {
            $this->assertContains($barangay['name'], config('visitdasol.barangays'));
            $this->assertGreaterThanOrEqual($bounds['south'], $barangay['lat']);
            $this->assertLessThanOrEqual($bounds['north'], $barangay['lat']);
            $this->assertGreaterThanOrEqual($bounds['west'], $barangay['lng']);
            $this->assertLessThanOrEqual($bounds['east'], $barangay['lng']);
        }

        $response->assertSee('Barangay names');
    }

    /* Street map and satellite ----------------------------------------------- */

    /**
     * The map carries everything the satellite switch needs.
     *
     * The switch itself is drawn by map.js and cannot be exercised here,
     * but it only appears when these three reach the page — and a
     * visitor to a coastal municipality looking for a beach wants the
     * imagery, so it must not quietly go missing.
     */
    public function test_the_map_carries_the_satellite_layer_and_its_credit(): void
    {
        $map = config('visitdasol.map');

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('data-satellite-url="'.e($map['satellite_url']).'"', false);
        $response->assertSee('data-satellite-max-zoom="'.$map['satellite_max_zoom'].'"', false);

        // Esri's imagery is free to use WITH the attribution, so the
        // credit travelling with it is a licence condition, not a nicety.
        $response->assertSee('data-satellite-attribution', false);
        $response->assertSee('Esri', false);
    }

    /**
     * Imagery reaches a closer zoom than the street map. If the two were
     * equal the switch would gain nothing, which is usually a sign the
     * satellite settings have been copied from the street ones.
     */
    public function test_the_imagery_zooms_closer_than_the_street_map(): void
    {
        $map = config('visitdasol.map');

        $this->assertGreaterThan(
            $map['max_zoom'],
            $map['satellite_max_zoom'],
            'Satellite imagery should reach a closer zoom than the street map.'
        );
    }

    public function test_the_map_page_says_where_the_switch_is(): void
    {
        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Satellite');
    }

    /**
     * A point nobody has confirmed must say so. Most of these are placed from
     * the elementary school that carries the barangay's name, which is a guide
     * and not a location, and a visitor has no way to tell the difference
     * unless the map tells them.
     */
    public function test_an_unconfirmed_barangay_point_is_marked_approximate(): void
    {
        config(['visitdasol.barangay_points' => [
            'Poblacion' => ['lat' => 15.9903076, 'lng' => 119.8796207, 'source' => 'place'],
            'Tambobong' => ['lat' => 15.9327468, 'lng' => 119.7823202, 'source' => 'landmark'],
            'Uli' => ['lat' => 15.9598656, 'lng' => 119.8204150, 'source' => 'office'],
        ]]);

        $barangays = collect($this->get(route('map'))->viewData('barangays'))
            ->map(fn ($b) => (array) $b)->keyBy('name');

        $this->assertFalse($barangays['Poblacion']['approximate'], 'a mapped place is not a guess');
        $this->assertFalse($barangays['Uli']['approximate'], 'the office confirmed this one');
        $this->assertTrue($barangays['Tambobong']['approximate'], 'placed from a landmark');
    }

    /**
     * Naming the landmark is both more honest and more useful than the word
     * "approximate": a visitor cannot navigate to a vague area, but they can
     * navigate to a school, and they can judge the pin for themselves.
     */
    public function test_a_barangay_point_says_which_landmark_it_was_taken_from(): void
    {
        config(['visitdasol.barangay_points' => [
            'Tambobong' => [
                'lat' => 15.9327468, 'lng' => 119.7823202,
                'source' => 'landmark', 'landmark' => 'Tambobong Elementary School',
            ],
            'Poblacion' => ['lat' => 15.9903076, 'lng' => 119.8796207, 'source' => 'place'],
        ]]);

        $barangays = collect($this->get(route('map'))->viewData('barangays'))
            ->map(fn ($b) => (array) $b)->keyBy('name');

        $this->assertSame('Tambobong Elementary School', $barangays['Tambobong']['landmark']);
        $this->assertNull($barangays['Poblacion']['landmark'], 'a mapped place needs no stand-in');

        // And it reaches the browser, which is where it gets read.
        $this->get(route('map'))->assertSee('Tambobong Elementary School', false);
    }

    /**
     * The road between any two places, not only between the visitor and a pin.
     * Somebody planning a day out from home is not standing at either end yet.
     */
    public function test_the_map_offers_a_road_distance_between_any_two_places(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        Destination::factory()->create(['name' => 'White Sand Cove']);

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('Road distance')
            ->assertSee('data-map-trip', false)
            ->assertSee('data-map-trip-from', false)
            ->assertSee('data-map-trip-to', false);

        $response->assertSee('Salt Breeze Resort')
            ->assertSee('White Sand Cove')
            ->assertSee('Brgy. Poblacion')
            ->assertSee('Where you are')
            ->assertSee('resort-'.$resort->id, false)
            ->assertSee('barangay-poblacion', false);
    }

    /**
     * The map and its controls are laid out as two panes, and the controls
     * still reach the map.
     *
     * map.js finds the "My location" button, the readout, the trip planner and
     * the climb profile inside the nearest [data-map-scope]. Before that
     * existed they had to be siblings of the map element, which is why they
     * were all stacked above it in one column: moving the button into the pane
     * where it belongs would have made it do nothing. Nothing here is visible
     * in a page render, so this is the only place it can be checked — a control
     * outside the scope fails silently in the browser.
     */
    public function test_the_map_controls_sit_inside_the_scope_the_script_searches(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $html = $this->get(route('map'))->assertOk()->getContent();

        $stage = strpos($html, 'data-map-scope');
        $this->assertNotFalse($stage, 'The map page no longer declares a [data-map-scope] wrapper.');

        // Where that wrapper ends: the attribution paragraph that follows it.
        $end = strpos($html, 'Map data from OpenStreetMap', $stage);
        $this->assertNotFalse($end, 'The attribution that closes the map stage is missing.');

        $scope = substr($html, $stage, $end - $stage);

        foreach ([
            'data-map-locate',
            'data-map-readout',
            'data-map-trip',
            'data-map-trip-from',
            'data-map-trip-to',
            'data-map-trip-me',
            'data-route-profile',
            'data-route-profile-body',
            'data-route-profile-key',
            'data-visit-map',
        ] as $hook) {
            $this->assertStringContainsString(
                $hook,
                $scope,
                "{$hook} is outside [data-map-scope], so map.js will never find it."
            );
        }

        // Panes, each one bounded, rather than one tall column: the
        // places, the map, and what has been picked.
        $this->assertStringContainsString('vd-gis__pane--list', $scope);
        $this->assertStringContainsString('vd-gis__pane--map', $scope);
        $this->assertStringContainsString('vd-gis__pane--info', $scope);
        $this->assertStringContainsString('vd-mapstage__scroll', $scope);
    }

    /**
     * "From" is typed, not chosen.
     *
     * Anyone travelling TO Dasol starts somewhere that is not in Dasol, so a
     * menu of local places could never answer "how far is it from Alaminos".
     * The places already on the map stay one tap away in the datalist.
     */
    public function test_the_starting_point_is_typed_and_not_limited_to_the_menu(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('<input class="form-control form-control-sm" type="text" id="trip-from"', false)
            ->assertSee('list="trip-from-places"', false)
            ->assertSee('<datalist id="trip-from-places">', false)
            // The map's own places are still offered as you type.
            ->assertSee('<option value="Salt Breeze Resort">', false)
            ->assertSee('<option value="Brgy. Poblacion">', false)
            // And the visitor's own position is still one tap.
            ->assertSee('Use where I am')
            ->assertSee(route('places.search'), false);
    }

    /**
     * "How far is this from me" is the question a guest reading one resort
     * page has, and until now they had to go back to the whole-municipality
     * map to ask it.
     */
    public function test_a_guest_can_put_themselves_on_a_detail_page_map(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $destination = Destination::factory()->create(['name' => 'White Sand Cove']);

        foreach ([route('resorts.show', $resort), route('destinations.show', $destination)] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('Where am I?')
                ->assertSee('data-locate-button', false)
                ->assertSee('data-locate-readout', false);
        }
    }

    /**
     * The office map is for checking where a pin sits, not for travelling to
     * it, so it does not ask staff for their location.
     */
    public function test_the_office_is_not_asked_for_its_location(): void
    {
        $resort = Resort::factory()->active()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.resorts.show', $resort))
            ->assertOk()
            ->assertDontSee('Where am I?');
    }

    /* Looking up a typed place ---------------------------------------------- */

    public function test_a_typed_place_is_turned_into_a_point(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([[
            'lat' => '16.1558',
            'lon' => '119.9811',
            'display_name' => 'Alaminos, Pangasinan, Ilocos Region, 2404, Philippines',
        ]])]);

        $this->get(route('places.search', ['q' => 'Alaminos']))
            ->assertOk()
            ->assertJson(['found' => true, 'name' => 'Alaminos, Pangasinan', 'lat' => 16.1558, 'lng' => 119.9811]);

        // The service must say who it is, or OpenStreetMap is entitled to
        // refuse it, and it must stay inside the Philippines.
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->header('User-Agent')[0] ?? '', config('app.name'))
                && $request['countrycodes'] === 'ph';
        });
    }

    public function test_a_place_nobody_can_find_is_answered_plainly(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        // 200 and found:false, not a 404: the request was fine, the answer is
        // simply "no such place", and a typo should not look like a broken route.
        $this->get(route('places.search', ['q' => 'Nowheresville']))
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }

    /**
     * Their terms ask callers not to hammer the service, so the same spelling
     * must never reach it twice &mdash; including a spelling that found nothing.
     */
    public function test_the_same_search_is_only_ever_asked_once(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->get(route('places.search', ['q' => 'Nowheresville']))->assertOk();
        $this->get(route('places.search', ['q' => 'nowheresville ']))->assertOk();
        $this->get(route('places.search', ['q' => 'Nowheresville']))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_an_empty_search_asks_nobody_anything(): void
    {
        Http::fake();

        $this->get(route('places.search', ['q' => '   ']))->assertStatus(422);

        Http::assertNothingSent();
    }

    /** A barangay with no point is left off rather than guessed at. */
    public function test_a_barangay_with_no_coordinates_is_left_off_the_map(): void
    {
        config(['visitdasol.barangay_points' => [
            'Poblacion' => ['lat' => 15.9903076, 'lng' => 119.8796207, 'source' => 'place'],
            'Alilao' => null,
            'San Vicente' => null,
        ]]);

        $names = collect($this->get(route('map'))->viewData('barangays'))
            ->map(fn ($b) => (array) $b)->pluck('name')->all();

        $this->assertSame(['Poblacion'], $names);
    }

    /** And the office is told which ones are missing, rather than left to count. */
    public function test_the_office_map_names_the_barangays_still_missing(): void
    {
        config(['visitdasol.barangay_points' => [
            'Poblacion' => ['lat' => 15.9903076, 'lng' => 119.8796207, 'source' => 'place'],
            'Alilao' => null,
            'San Vicente' => null,
        ]]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.map'))
            ->assertOk()
            ->assertSee('has no point on the map yet')
            ->assertSee('Alilao, San Vicente');
    }

    public function test_the_public_map_shows_approved_resorts_and_published_destinations(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort', 'latitude' => 16.01, 'longitude' => 119.87]);
        $destination = Destination::factory()->create(['name' => 'White Sand Cove']);

        $response = $this->get(route('map'));
        $response->assertOk()->assertSee('Salt Breeze Resort')->assertSee('White Sand Cove');

        $points = collect($this->pointsFrom($response));
        $this->assertCount(2, $points);

        $resortPoint = $points->firstWhere('id', 'resort-'.$resort->id);
        $this->assertSame('resort', $resortPoint['type']);
        $this->assertSame(16.01, $resortPoint['lat']);
        $this->assertSame(119.87, $resortPoint['lng']);
        $this->assertSame(route('resorts.show', $resort), $resortPoint['url']);

        $this->assertSame('destination', $points->firstWhere('id', 'destination-'.$destination->id)['type']);
    }

    public function test_the_public_map_leaves_out_anything_not_published(): void
    {
        Resort::factory()->create(['name' => 'Pending Resort']);
        Resort::factory()->inactive()->create(['name' => 'Offline Resort']);
        Resort::factory()->rejected()->create(['name' => 'Rejected Resort']);
        $hiddenOwner = User::factory()->resortOwner(UserStatus::Inactive)->create();
        Resort::factory()->for($hiddenOwner, 'owner')->active()->create(['name' => 'Hidden Owner Resort']);
        Destination::factory()->inactive()->create(['name' => 'Hidden Falls']);

        $response = $this->get(route('map'));

        $response->assertOk()
            ->assertDontSee('Pending Resort')
            ->assertDontSee('Offline Resort')
            ->assertDontSee('Rejected Resort')
            ->assertDontSee('Hidden Owner Resort')
            ->assertDontSee('Hidden Falls');

        $this->assertSame([], $this->pointsFrom($response));
    }

    /**
     * With no listings the map is still worth drawing, because the barangays
     * are on it. The empty state is for a system that knows nothing at all.
     */
    public function test_the_map_still_draws_when_there_are_no_listings(): void
    {
        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('Nothing on the map yet')
            ->assertSee('Barangay names');
    }

    public function test_the_map_page_works_with_nothing_to_show(): void
    {
        config(['visitdasol.barangay_points' => []]);

        $this->get(route('map'))->assertOk()->assertSee('Nothing on the map yet');
    }

    public function test_coordinates_come_from_the_database(): void
    {
        $resort = Resort::factory()->active()->create(['latitude' => 15.9876543, 'longitude' => 119.8765432]);

        $response = $this->get(route('map'))->assertOk();

        // The page embeds the stored values; nothing is hard-coded in JavaScript.
        $response->assertSee('15.9876543', false);
        $response->assertSee('119.8765432', false);
        $this->assertSame(15.9876543, $this->pointsFrom($response)[0]['lat']);
        $this->assertStringNotContainsString(
            '15.9876543',
            file_get_contents(public_path('js/map.js')),
            'Locations must never be written into the JavaScript file.'
        );
    }

    public function test_names_with_markup_are_escaped_in_the_embedded_data(): void
    {
        Resort::factory()->active()->create(['name' => 'Sneaky <script>alert(1)</script> Resort']);

        $response = $this->get(route('map'))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('\u003Cscript\u003E', false);
    }

    public function test_guests_can_open_the_map(): void
    {
        Resort::factory()->active()->create();

        $this->get(route('map'))->assertOk();
        $this->assertGuest();
    }

    /* The office map ----------------------------------------------------- */

    public function test_the_office_map_shows_every_listing_with_its_status(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        Resort::factory()->create(['name' => 'Pending Resort']);
        Destination::factory()->inactive()->create(['name' => 'Hidden Falls']);

        $response = $this->actingAs(User::factory()->superAdmin()->create())->get(route('admin.map'));

        $response->assertOk()
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Pending Resort')
            ->assertSee('Hidden Falls')
            ->assertSee('Not published');

        $points = collect($this->pointsFrom($response));
        $this->assertCount(3, $points);
        $this->assertSame('pending', $points->firstWhere('name', 'Pending Resort')['status']);
    }

    public function test_only_the_office_can_open_its_map(): void
    {
        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.map'))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('admin.map'))->assertRedirect(route('login'));
    }

    /* Mini-maps on detail pages ------------------------------------------- */

    public function test_detail_pages_include_a_map_of_the_place(): void
    {
        $resort = Resort::factory()->active()->create(['latitude' => 16.005, 'longitude' => 119.872]);
        $destination = Destination::factory()->create(['latitude' => 15.995, 'longitude' => 119.881]);

        $this->get(route('resorts.show', $resort))
            ->assertOk()
            ->assertSee('data-location-map', false)
            ->assertSee('16.005', false)
            ->assertSee('Get directions');

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('data-location-map', false)
            ->assertSee('15.995', false);
    }

    public function test_the_map_link_is_in_the_navigation(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(route('map'), false);
    }
}
