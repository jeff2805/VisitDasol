<?php

namespace Tests\Feature\Map;

use App\Services\BarangayPoints;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Finding the barangays that still have no point, without guessing one.
 *
 * The danger this guards against is subtle. Nominatim answers a search
 * for "Hermosa, Dasol" with a school on the Zambales Highway whose
 * address says only "Dasol" — it matched some words, somewhere, and
 * that is not a claim about Hermosa. Writing it down anyway is exactly
 * how a wrong pin gets onto a municipal map and stays there for years.
 *
 * So every candidate is handed back to the service, which is asked what
 * barangay is at that point, and only an address naming the barangay is
 * accepted.
 */
class BarangayPointLookupTest extends TestCase
{
    /* The points as they stand ------------------------------------------- */

    public function test_every_configured_point_sits_inside_dasol(): void
    {
        $bounds = config('visitdasol.map.bounds');

        foreach (app(BarangayPoints::class)->mappable() as $point) {
            $this->assertContains($point['name'], config('visitdasol.barangays'));

            $this->assertGreaterThanOrEqual($bounds['south'], $point['lat'], $point['name']);
            $this->assertLessThanOrEqual($bounds['north'], $point['lat'], $point['name']);
            $this->assertGreaterThanOrEqual($bounds['west'], $point['lng'], $point['name']);
            $this->assertLessThanOrEqual($bounds['east'], $point['lng'], $point['name']);
        }
    }

    /**
     * Confirmed on 26 Oct 2026: OpenStreetMap's reverse lookup at this
     * point returns address.village = "Magsaysay". That evidence is
     * what moved it off the missing list, and this pins the figure so
     * a stray edit cannot quietly move the barangay.
     */
    public function test_magsaysay_has_the_point_that_was_verified(): void
    {
        $point = collect(config('visitdasol.barangay_points'))->get('Magsaysay');

        $this->assertNotNull($point, 'Magsaysay lost its point.');
        $this->assertEqualsWithDelta(15.9913222, $point['lat'], 0.0000001);
        $this->assertEqualsWithDelta(119.8577853, $point['lng'], 0.0000001);
        $this->assertSame('Juan Bustamante Elementary School', $point['landmark']);
    }

    /**
     * A point taken from a landmark is a guide to the area, not the
     * barangay's centre, and the map says so. Only something the office
     * itself confirmed may drop the warning.
     */
    public function test_a_landmark_point_is_still_shown_as_approximate(): void
    {
        $magsaysay = app(BarangayPoints::class)
            ->mappable()
            ->firstWhere('name', 'Magsaysay');

        $this->assertTrue($magsaysay['approximate']);
        $this->assertSame('Juan Bustamante Elementary School', $magsaysay['landmark']);
    }

    public function test_the_barangays_with_no_point_are_still_named(): void
    {
        $missing = app(BarangayPoints::class)->missing();

        // Whatever is unplaced, every name must be a real barangay —
        // never a typo quietly dropping one off the map.
        foreach ($missing as $name) {
            $this->assertContains($name, config('visitdasol.barangays'));
        }

        $this->assertSame(
            18,
            app(BarangayPoints::class)->mappable()->count() + count($missing)
        );
    }

    /* The command ---------------------------------------------------------- */

    /**
     * A search hit whose address does NOT name the barangay is thrown
     * away. This is the Hermosa case, reproduced exactly.
     */
    public function test_a_hit_the_service_cannot_place_is_rejected(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '15.9395093',
                'lon' => '119.8684085',
                'name' => 'Don Juan Bernal, Sr. Elementary School',
            ]]),

            // The reverse lookup names the town and no barangay.
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['town' => 'Dasol', 'state' => 'Pangasinan'],
            ]),
        ]);

        $this->artisan('visitdasol:barangay-points')
            ->expectsOutputToContain('could not be confirmed')
            ->assertSuccessful();
    }

    /**
     * A hit the service places inside the barangay is kept, and printed
     * ready to paste.
     */
    public function test_a_hit_the_service_places_in_the_barangay_is_kept(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '15.9913222',
                'lon' => '119.8577853',
                'name' => 'Juan Bustamante Elementary School',
            ]]),

            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['village' => 'Alilao', 'town' => 'Dasol'],
            ]),
        ]);

        /*
         * Short fragments only. The paste line is longer than a
         * terminal is wide and the console wraps it, at a column that
         * depends on the window — so a longer needle fails on where the
         * break landed rather than on anything that matters.
         */
        $this->artisan('visitdasol:barangay-points')
            ->expectsOutputToContain('Confirmed.')
            ->expectsOutputToContain("'Alilao' =>")
            ->assertSuccessful();
    }

    /**
     * A tilde must not cost the map a pin: OSM spells it both ways.
     */
    public function test_an_accent_does_not_defeat_the_match(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '15.9232469',
                'lon' => '119.7997713',
                'name' => 'Some Landmark',
            ]]),

            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['village' => 'Osmena'],
            ]),
        ]);

        $this->artisan('visitdasol:barangay-points')
            ->expectsOutputToContain('Osmeña')
            ->assertSuccessful();
    }

    /** A point outside the municipal box is never a Dasol barangay. */
    public function test_a_hit_outside_dasol_is_rejected(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                // Manila.
                'lat' => '14.5995',
                'lon' => '120.9842',
                'name' => 'Somewhere else entirely',
            ]]),

            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['village' => 'Alilao'],
            ]),
        ]);

        $this->artisan('visitdasol:barangay-points')
            ->expectsOutputToContain('could not be confirmed')
            ->assertSuccessful();
    }

    /**
     * The service being busy is not a reason for the command to fall
     * over — Overpass and Nominatim both refuse callers regularly.
     */
    public function test_a_failing_service_is_reported_not_thrown(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

        $this->artisan('visitdasol:barangay-points')
            ->expectsOutputToContain('could not be confirmed')
            ->assertSuccessful();
    }

    /**
     * WRITES NOTHING. A barangay pin gets printed on tarpaulins; a
     * person decides what goes in, not a cron job.
     */
    public function test_the_command_never_writes_to_the_configuration(): void
    {
        $before = config('visitdasol.barangay_points');

        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '15.95', 'lon' => '119.85', 'name' => 'Anything',
            ]]),
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'address' => ['village' => 'Alilao'],
            ]),
        ]);

        $this->artisan('visitdasol:barangay-points')->assertSuccessful();

        $this->assertSame($before, config('visitdasol.barangay_points'));
        $this->assertStringNotContainsString(
            'file_put_contents',
            file_get_contents(app_path('Console/Commands/BarangayPointLookup.php'))
        );
    }

    public function test_the_command_identifies_itself_to_the_service(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $this->artisan('visitdasol:barangay-points')->assertSuccessful();

        // Nominatim refuses callers that will not say who they are.
        Http::assertSent(fn ($request) => str_contains(
            $request->header('User-Agent')[0] ?? '',
            'VisitDasol'
        ));
    }
}
