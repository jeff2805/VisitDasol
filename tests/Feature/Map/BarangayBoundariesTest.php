<?php

namespace Tests\Feature\Map;

use App\Services\BarangayBoundaries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The barangay boundary layer.
 *
 * The outlines themselves are not in this repository and are not drawn
 * here: a barangay boundary is a legal line, OpenStreetMap holds none
 * for Dasol, and the project already refuses to guess a single point.
 * What is tested is that a file the office supplies is read correctly,
 * that a bad one cannot take the map down, and that the neighbours'
 * shapes never end up on Dasol's map.
 */
class BarangayBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private BarangayBoundaries $boundaries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boundaries = app(BarangayBoundaries::class);

        File::delete($this->boundaries->path());
    }

    protected function tearDown(): void
    {
        File::delete($this->boundaries->path());

        parent::tearDown();
    }

    private function square(float $lat, float $lng): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$lng, $lat], [$lng + 0.01, $lat],
                [$lng + 0.01, $lat + 0.01], [$lng, $lat + 0.01], [$lng, $lat],
            ]],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $named  [property key, value]
     */
    private function writeFile(array $named): void
    {
        $features = [];
        $lat = 15.95;

        foreach ($named as [$key, $value]) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [$key => $value],
                'geometry' => $this->square($lat, 119.87),
            ];

            $lat += 0.02;
        }

        File::ensureDirectoryExists(dirname($this->boundaries->path()));

        File::put($this->boundaries->path(), json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]));
    }

    /* No file yet ------------------------------------------------------------ */

    public function test_a_map_with_no_boundary_file_still_works(): void
    {
        $this->assertFalse($this->boundaries->exists());
        $this->assertNull($this->boundaries->featureCollection());

        $this->get(route('map'))
            ->assertOk()
            // The name labels it already drew are still there.
            ->assertSee('Barangay names')
            ->assertDontSee('Barangay boundaries');
    }

    public function test_the_coverage_command_says_the_file_is_missing(): void
    {
        $this->artisan('visitdasol:barangay-coverage')
            ->expectsOutputToContain('No boundary file yet.')
            ->assertSuccessful();
    }

    /* A file the office supplied ---------------------------------------------- */

    public function test_outlines_reach_the_map_once_the_file_is_there(): void
    {
        $this->writeFile([['name', 'Poblacion'], ['name', 'Tambobong']]);

        $collection = $this->boundaries->featureCollection();

        $this->assertCount(2, $collection['features']);
        $this->assertSame('Poblacion', $collection['features'][0]['properties']['name']);

        // The id the list beside the map uses to highlight an outline.
        $this->assertSame('barangay-poblacion', $collection['features'][0]['properties']['id']);

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('Barangay boundaries')
            ->assertSee('data-barangay-shapes', false);
    }

    /**
     * Every source spells the property differently, and an office should
     * not have to rewrite a download to use it.
     */
    public function test_the_name_is_found_however_the_source_spells_the_property(): void
    {
        foreach (['name', 'NAME', 'barangay', 'BARANGAY', 'ADM4_EN', 'NAME_3', 'brgy'] as $key) {
            $this->writeFile([[$key, 'Poblacion']]);

            $collection = $this->boundaries->featureCollection();

            $this->assertNotNull($collection, "A file using '{$key}' was not read.");
            $this->assertSame('Poblacion', $collection['features'][0]['properties']['name']);
        }
    }

    /**
     * A download is as likely to say OSMENA as Osmeña, and dropping a
     * real barangay over a missing tilde would be a silently short map.
     */
    public function test_an_accent_or_a_different_case_still_matches(): void
    {
        foreach (['Osmeña', 'OSMENA', 'osmena', 'Osmena'] as $spelling) {
            $this->writeFile([['name', $spelling]]);

            $collection = $this->boundaries->featureCollection();

            $this->assertNotNull($collection, "'{$spelling}' was not matched.");

            // The config's spelling wins, so the label on the map reads
            // the same as the name in every menu beside it.
            $this->assertSame('Osmeña', $collection['features'][0]['properties']['name']);
        }
    }

    /**
     * A province-wide file should be usable as it comes. Infanta's
     * barangays must not appear on Dasol's map.
     */
    public function test_a_shape_belonging_to_another_municipality_is_dropped(): void
    {
        $this->writeFile([
            ['name', 'Poblacion'],
            ['name', 'Cato'],        // Infanta
            ['name', 'Bamban'],      // Infanta
        ]);

        $collection = $this->boundaries->featureCollection();

        $this->assertCount(1, $collection['features']);
        $this->assertSame('Poblacion', $collection['features'][0]['properties']['name']);
    }

    public function test_coverage_names_what_is_there_and_what_is_not(): void
    {
        $this->writeFile([['name', 'Poblacion'], ['name', 'Uli']]);

        $coverage = $this->boundaries->coverage();

        $this->assertSame(['Poblacion', 'Uli'], $coverage['present']);
        $this->assertContains('Tambobong', $coverage['missing']);
        $this->assertNotContains('Poblacion', $coverage['missing']);

        // 18 barangays in all.
        $this->assertCount(16, $coverage['missing']);
    }

    public function test_the_coverage_command_lists_the_gaps(): void
    {
        $this->writeFile([['name', 'Poblacion']]);

        $this->artisan('visitdasol:barangay-coverage')
            ->expectsOutputToContain('Poblacion')
            ->expectsOutputToContain('still missing')
            ->assertSuccessful();
    }

    /* A file that is wrong ------------------------------------------------------ */

    public function test_a_broken_file_does_not_take_the_map_down(): void
    {
        File::ensureDirectoryExists(dirname($this->boundaries->path()));
        File::put($this->boundaries->path(), '{ this is not json');

        $this->assertNull($this->boundaries->featureCollection());

        $this->get(route('map'))->assertOk();
    }

    public function test_a_file_whose_shapes_match_nothing_is_reported(): void
    {
        $this->writeFile([['name', 'Cato'], ['name', 'Bamban']]);

        $this->assertNull($this->boundaries->featureCollection());

        $this->artisan('visitdasol:barangay-coverage')
            ->expectsOutputToContain('none of its shapes matched')
            ->assertFailed();
    }

    public function test_a_feature_with_no_geometry_is_skipped(): void
    {
        File::ensureDirectoryExists(dirname($this->boundaries->path()));

        File::put($this->boundaries->path(), json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['name' => 'Poblacion']],
                [
                    'type' => 'Feature',
                    'properties' => ['name' => 'Uli'],
                    'geometry' => $this->square(15.96, 119.82),
                ],
            ],
        ]));

        $collection = $this->boundaries->featureCollection();

        $this->assertCount(1, $collection['features']);
        $this->assertSame('Uli', $collection['features'][0]['properties']['name']);
    }

    /* Kept off the basemap -------------------------------------------------------- */

    /**
     * The outlines have to be readable over the street map AND over the
     * imagery, so they are their own overlay rather than part of either
     * basemap. The switch between the two must not take them away.
     */
    public function test_the_outlines_are_an_overlay_and_not_part_of_a_basemap(): void
    {
        $this->writeFile([['name', 'Poblacion']]);

        $response = $this->get(route('map'))->assertOk();

        // Shapes travel on their own attribute, beside the satellite
        // settings rather than inside them.
        $response->assertSee('data-barangay-shapes', false);
        $response->assertSee('data-satellite-url', false);
    }
}
