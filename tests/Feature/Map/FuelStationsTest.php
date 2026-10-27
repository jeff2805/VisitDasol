<?php

namespace Tests\Feature\Map;

use App\Services\FuelStations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Filling stations on the public map, from OpenStreetMap.
 *
 * Overpass is never called in these tests. What is being checked is that
 * the map reads only what was stored, that a busy afternoon at a free
 * service cannot empty a working layer, and that volunteer-written names
 * reach the page as text.
 */
class FuelStationsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://overpass-api.de/api/interpreter';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'visitdasol.map.fuel_url' => [self::ENDPOINT],
            'visitdasol.map.fuel_bounds' => [
                'south' => 15.87, 'west' => 119.76, 'north' => 16.02, 'east' => 119.95,
            ],
        ]);

        Cache::flush();
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    private function overpassReturns(array $elements): void
    {
        Http::fake([
            '*overpass*' => Http::response(['elements' => $elements]),
        ]);
    }

    private function node(float $lat, float $lng, array $tags = [], int $id = 1): array
    {
        return ['type' => 'node', 'id' => $id, 'lat' => $lat, 'lon' => $lng, 'tags' => $tags];
    }

    /* Reading what was stored ------------------------------------------------ */

    public function test_the_map_never_calls_overpass_itself(): void
    {
        Http::fake();

        app(FuelStations::class)->inDasol();
        $this->get(route('map'))->assertOk();

        /*
         * The whole point of the refresh command. A visitor opening the
         * map must never wait on a free third-party service that
         * routinely takes ten seconds.
         */
        Http::assertNothingSent();
    }

    public function test_nothing_is_shown_until_the_refresh_has_run(): void
    {
        $this->assertSame([], app(FuelStations::class)->inDasol());

        $this->get(route('map'))->assertOk()->assertDontSee('Filling stations');
    }

    public function test_the_refresh_stores_what_the_map_then_reads(): void
    {
        $this->overpassReturns([
            $this->node(15.9886, 119.8751, ['amenity' => 'fuel', 'name' => 'Den Den Fuel Mart'], 1),
            $this->node(15.9940, 119.8738, ['amenity' => 'fuel', 'name' => 'Veltron'], 2),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $stations = app(FuelStations::class)->inDasol();

        $this->assertCount(2, $stations);
        $this->assertSame('Den Den Fuel Mart', $stations[0]['name']);
        $this->assertSame(15.9886, $stations[0]['lat']);

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Filling stations')
            ->assertSee('Den Den Fuel Mart');
    }

    /* A forecourt mapped as an outline ----------------------------------------- */

    public function test_a_station_mapped_as_an_outline_still_gets_a_pin(): void
    {
        $this->overpassReturns([
            [
                'type' => 'way', 'id' => 77,
                'center' => ['lat' => 15.95, 'lon' => 119.88],
                'tags' => ['amenity' => 'fuel', 'name' => 'Petron'],
            ],
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $stations = app(FuelStations::class)->inDasol();

        $this->assertCount(1, $stations);
        $this->assertSame(15.95, $stations[0]['lat']);
    }

    public function test_an_element_with_no_position_is_dropped(): void
    {
        $this->overpassReturns([
            ['type' => 'way', 'id' => 9, 'tags' => ['amenity' => 'fuel', 'name' => 'Nowhere']],
            $this->node(15.95, 119.88, ['amenity' => 'fuel', 'name' => 'Real One'], 10),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $stations = app(FuelStations::class)->inDasol();

        $this->assertCount(1, $stations);
        $this->assertSame('Real One', $stations[0]['name']);
    }

    /* Names and brands ----------------------------------------------------------- */

    public function test_an_unnamed_station_falls_back_to_its_brand_then_to_a_label(): void
    {
        $this->overpassReturns([
            $this->node(15.95, 119.88, ['amenity' => 'fuel', 'brand' => 'Petron'], 1),
            $this->node(15.96, 119.88, ['amenity' => 'fuel'], 2),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $stations = app(FuelStations::class)->inDasol();

        $this->assertSame('Petron', $stations[0]['name']);
        $this->assertSame('Filling station', $stations[1]['name']);
    }

    public function test_a_brand_that_is_already_the_name_is_not_repeated(): void
    {
        $this->overpassReturns([
            $this->node(15.95, 119.88, ['amenity' => 'fuel', 'name' => 'Petron', 'brand' => 'Petron'], 1),
            $this->node(15.96, 119.88, ['amenity' => 'fuel', 'name' => 'Jett Fuel', 'brand' => 'Jetti'], 2),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $stations = app(FuelStations::class)->inDasol();

        $this->assertNull($stations[0]['brand']);
        $this->assertSame('Jetti', $stations[1]['brand']);
    }

    /* When Overpass will not answer ------------------------------------------------ */

    /**
     * The failure this design exists for. Overpass answers 502 whenever
     * its queue is full, and a map that was fine yesterday must not empty
     * because a free service had a busy afternoon.
     */
    public function test_a_failed_refresh_leaves_the_stored_stations_alone(): void
    {
        $this->overpassReturns([
            $this->node(15.9886, 119.8751, ['amenity' => 'fuel', 'name' => 'Den Den Fuel Mart'], 1),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();
        $this->assertCount(1, app(FuelStations::class)->inDasol());

        Http::fake(['*overpass*' => Http::response('at capacity', 502)]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        // Still there.
        $this->assertCount(1, app(FuelStations::class)->inDasol());
    }

    public function test_a_second_mirror_is_tried_when_the_first_is_busy(): void
    {
        config(['visitdasol.map.fuel_url' => [
            'https://busy.example/api/interpreter',
            'https://spare.example/api/interpreter',
        ]]);

        Http::fake([
            'busy.example/*' => Http::response('at capacity', 502),
            'spare.example/*' => Http::response(['elements' => [
                $this->node(15.95, 119.88, ['amenity' => 'fuel', 'name' => 'Veltron'], 1),
            ]]),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $this->assertSame('Veltron', app(FuelStations::class)->inDasol()[0]['name']);
    }

    public function test_a_body_that_is_not_an_overpass_answer_is_not_stored(): void
    {
        Http::fake(['*overpass*' => Http::response('<html>error page</html>', 200)]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $this->assertSame([], app(FuelStations::class)->inDasol());
    }

    public function test_the_layer_can_be_switched_off_entirely(): void
    {
        config(['visitdasol.map.fuel_url' => []]);
        Http::fake();

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame([], app(FuelStations::class)->inDasol());
    }

    /* The box ------------------------------------------------------------------------ */

    /**
     * The box is part of the storage key, so an office that tightens it
     * is not served yesterday's answer for yesterday's box.
     */
    public function test_tightening_the_box_does_not_serve_the_old_answer(): void
    {
        $this->overpassReturns([
            $this->node(15.95, 119.88, ['amenity' => 'fuel', 'name' => 'Den Den Fuel Mart'], 1),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();
        $this->assertCount(1, app(FuelStations::class)->inDasol());

        config(['visitdasol.map.fuel_bounds' => [
            'south' => 15.98, 'west' => 119.86, 'north' => 16.00, 'east' => 119.89,
        ]]);

        $this->assertSame([], app(FuelStations::class)->inDasol());
    }

    public function test_the_box_asked_about_is_the_one_configured(): void
    {
        $this->overpassReturns([]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        Http::assertSent(function ($request) {
            $sent = urldecode((string) $request->body());

            // The tight Dasol box, not the generous map bounds, which
            // would drag in Infanta's and Burgos's stations.
            return str_contains($sent, '15.87,119.76,16.02,119.95')
                && str_contains($sent, '"amenity"="fuel"');
        });
    }

    /* Safety -------------------------------------------------------------------------- */

    /**
     * These names are written by strangers on OpenStreetMap. The popup
     * builds them as text nodes in map.js, and nothing may put them into
     * the page as markup on the way.
     */
    public function test_a_name_from_a_stranger_reaches_the_page_as_text(): void
    {
        $this->overpassReturns([
            $this->node(15.95, 119.88, [
                'amenity' => 'fuel',
                'name' => '<script>alert(1)</script>',
            ], 1),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_an_absurdly_long_tag_is_not_shown(): void
    {
        $this->overpassReturns([
            $this->node(15.95, 119.88, [
                'amenity' => 'fuel',
                'name' => str_repeat('x', 500),
            ], 1),
        ]);

        $this->artisan('visitdasol:refresh-fuel')->assertSuccessful();

        $this->assertSame('Filling station', app(FuelStations::class)->inDasol()[0]['name']);
    }
}
