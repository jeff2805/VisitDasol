<?php

namespace Tests\Feature\Map;

use App\Models\Resort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Names on the satellite view.
 *
 * Aerial photography carries no writing. The imagery showed the
 * coastline beautifully and could not tell anybody which road they were
 * looking at, so two transparent reference layers are drawn over it:
 * one with place and locality names, one with roads and theirs.
 *
 * The trap this guards against is the Content Security Policy. Tile
 * hosts are read from config and written into img-src by
 * SecurityHeaders; a layer added to the map but not to the config list
 * is refused by the browser with nothing in the page to show for it.
 */
class SatelliteLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function mapJs(): string
    {
        return file_get_contents(public_path('js/map.js'));
    }

    /* Configuration ------------------------------------------------------- */

    public function test_the_imagery_has_label_overlays_configured(): void
    {
        $map = config('visitdasol.map');

        $this->assertNotNull($map['satellite_labels_url']);
        $this->assertNotNull($map['satellite_roads_url']);

        foreach (['satellite_labels_url', 'satellite_roads_url'] as $key) {
            $this->assertStringContainsString('{z}', $map[$key]);
            $this->assertStringContainsString('{x}', $map[$key]);
            $this->assertStringContainsString('{y}', $map[$key]);
            $this->assertStringStartsWith('https://', $map[$key]);
        }
    }

    /**
     * The imagery is still Esri's World Imagery. This redesign adds
     * names to it; it does not replace it.
     */
    public function test_the_existing_imagery_is_untouched(): void
    {
        $this->assertSame(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            config('visitdasol.map.satellite_url')
        );

        $this->assertStringContainsString('Esri', config('visitdasol.map.satellite_attribution'));
    }

    /* The policy that would silently break them --------------------------- */

    public function test_the_security_policy_allows_the_label_tiles(): void
    {
        $csp = $this->get(route('map'))->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);

        $host = parse_url(config('visitdasol.map.satellite_labels_url'), PHP_URL_HOST);

        $this->assertStringContainsString($host, $csp,
            'The label tile host is missing from img-src, so the browser will refuse the tiles.');

        // And the images are still only allowed as images.
        $this->assertStringContainsString('img-src', $csp);
    }

    /**
     * Named in the policy from config, so moving the imagery to another
     * provider cannot leave the label hosts behind.
     */
    public function test_the_policy_is_built_from_the_config_not_hard_coded(): void
    {
        config(['visitdasol.map.satellite_labels_url' => 'https://labels.example.org/{z}/{y}/{x}.png']);

        $csp = $this->get(route('map'))->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('labels.example.org', $csp);
    }

    /* What reaches the page ------------------------------------------------ */

    public function test_the_map_element_carries_both_overlays(): void
    {
        Resort::factory()->active()->create();

        $map = config('visitdasol.map');

        $this->get(route('map'))
            ->assertOk()
            ->assertSee('data-satellite-labels-url="'.e($map['satellite_labels_url']).'"', false)
            ->assertSee('data-satellite-roads-url="'.e($map['satellite_roads_url']).'"', false);
    }

    /**
     * Dropping an overlay in config must drop it from the page, not
     * render an empty attribute the script then treats as a URL.
     */
    public function test_an_overlay_switched_off_is_not_sent(): void
    {
        Resort::factory()->active()->create();

        config(['visitdasol.map.satellite_roads_url' => null]);

        $this->get(route('map'))
            ->assertOk()
            ->assertDontSee('data-satellite-roads-url', false)
            ->assertSee('data-satellite-labels-url', false);
    }

    /* The rules the script must keep --------------------------------------- */

    /**
     * Above the imagery, below every marker. A name drawn over a resort
     * pin is a name sitting on the thing somebody is trying to tap.
     */
    public function test_labels_sit_in_their_own_pane_between_tiles_and_markers(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString("map.createPane('vd-labels')", $source);
        $this->assertStringContainsString("zIndex = 350", $source);

        // Leaflet's own panes: tiles 200, overlays 400, markers 600.
        $this->assertStringContainsString("pane: 'vd-labels'", $source);

        // And nothing on a label tile may swallow a tap.
        $this->assertStringContainsString("pointerEvents = 'none'", $source);
    }

    /**
     * The street map already has its names drawn in. Laying these over
     * it would print every name twice, slightly offset.
     */
    public function test_the_labels_travel_with_the_imagery_only(): void
    {
        $source = $this->mapJs();

        $this->assertMatchesRegularExpression(
            '/labelLayers\.forEach.*?wantsSatellite && !map\.hasLayer\(layer\).*?layer\.addTo\(map\)/s',
            $source,
            'Label overlays must be added only in satellite mode.'
        );

        $this->assertMatchesRegularExpression(
            '/labelLayers\.forEach.*?!wantsSatellite && map\.hasLayer\(layer\).*?map\.removeLayer\(layer\)/s',
            $source,
            'Label overlays must be removed again in street mode.'
        );
    }

    /* Decluttering without duplicating ------------------------------------- */

    /**
     * The overlap is fixed by drawing the secondary pins smaller when
     * the map is far out, NOT by adding and removing layers — that
     * would fight the layers control and could leave two of a marker.
     */
    public function test_crowding_is_eased_by_zoom_bands_not_by_new_markers(): void
    {
        $source = $this->mapJs();

        $this->assertStringContainsString("element.classList.toggle('is-zoom-far'", $source);
        $this->assertStringContainsString("element.classList.toggle('is-zoom-near'", $source);
        $this->assertStringContainsString("map.on('zoomend', showZoomBand)", $source);

        // One layer group per kind, created once, as before.
        $this->assertSame(1, substr_count($source, 'var fuelLayer = L.layerGroup()'));
        $this->assertSame(1, substr_count($source, 'var checkpointLayer = L.layerGroup()'));
    }

    public function test_the_stylesheet_carries_the_zoom_bands_and_the_satellite_halo(): void
    {
        $css = file_get_contents(public_path('css/visitdasol.css'));

        foreach ([
            '.vd-map.is-zoom-far .vd-brgy',
            '.vd-map.is-zoom-near .vd-brgy',
            '.vd-map.is-satellite .vd-brgy',
            '.vd-map.is-satellite .leaflet-tooltip.vd-brgy-label',
            '.vd-map.is-zoom-far .vd-pin--fuel',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css, "Missing CSS rule: {$rule}");
        }
    }

    public function test_the_satellite_class_follows_the_basemap_switch(): void
    {
        $this->assertStringContainsString(
            "element.classList.toggle('is-satellite', wantsSatellite)",
            $this->mapJs()
        );
    }

    /* Everything that was already there ------------------------------------ */

    public function test_the_existing_layers_and_markers_still_reach_the_page(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $response = $this->get(route('map'))->assertOk();

        $response->assertSee('Salt Breeze Resort')
            ->assertSee('data-barangays', false)
            ->assertSee('data-points', false)
            ->assertSee('Barangay names')
            ->assertSee('Satellite')
            ->assertSee('Road distance');
    }
}
