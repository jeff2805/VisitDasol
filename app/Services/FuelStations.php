<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where a visitor can fill up in Dasol.
 *
 * Read from OpenStreetMap through its Overpass service, from the server
 * and cached, exactly as PlaceFinder reads its search. Never typed in by
 * hand: nobody here has surveyed Dasol's filling stations, and the
 * barangay points in config/visitdasol.php already say what this project
 * thinks of a guessed pin on a municipal map.
 *
 * TWO THINGS THIS CANNOT DO, and both are visible to the visitor rather
 * than hidden:
 *
 *   1. OpenStreetMap has no boundary for Dasol, only a point, so "in
 *      Dasol" is a rectangle (map.fuel_bounds) and not the municipality's
 *      real shape. A station just over the line either way is possible.
 *   2. OpenStreetMap is edited by volunteers. A station may be missing,
 *      closed, or renamed. The map says where the pins came from so a
 *      visitor can weigh them, and the office can correct the box.
 *
 * NOTHING HERE IS FETCHED WHILE A VISITOR WAITS. Overpass is a free
 * service that regularly takes ten seconds or answers 502 when its queue
 * is full, and the map page must not be held up by either. The map reads
 * the stored answer and nothing else; visitdasol:refresh-fuel goes and
 * gets it, on a schedule and by hand.
 *
 * So a map with no stored answer yet shows no filling stations, which is
 * the right way round: a layer that is missing is better than a map that
 * takes half a minute to open.
 */
class FuelStations
{
    /**
     * Every filling station inside the configured box, as last fetched.
     *
     * Reads storage only. Never calls Overpass, never blocks a page.
     *
     * @return list<array{id: string, name: string, brand: ?string, lat: float, lng: float}>
     */
    public function inDasol(): array
    {
        $stored = Cache::get($this->key());

        return is_array($stored) ? $stored : [];
    }

    /**
     * Go and ask OpenStreetMap, and store the answer.
     *
     * Called by visitdasol:refresh-fuel, never by a page. Returns the
     * stations found, or null when no mirror could be reached — in which
     * case whatever was stored before is deliberately LEFT ALONE. A busy
     * afternoon at Overpass must not empty a map that was fine yesterday.
     *
     * @return list<array{id: string, name: string, brand: ?string, lat: float, lng: float}>|null
     */
    public function refresh(): ?array
    {
        // One endpoint or several; the config may name either.
        $urls = array_values(array_filter(
            (array) config('visitdasol.map.fuel_url'),
            fn ($url) => is_string($url) && $url !== ''
        ));

        $bounds = config('visitdasol.map.fuel_bounds');

        if ($urls === [] || ! is_array($bounds)) {
            return null;
        }

        /*
         * The mirrors in turn. A busy server answers 502 rather than
         * failing to connect, so moving on to the next one is the
         * difference between an answer and none. The first real one wins.
         */
        foreach ($urls as $url) {
            $stations = $this->ask($url, $bounds);

            if ($stations !== null) {
                /*
                 * Kept far longer than the refresh interval on purpose.
                 * A fortnight of failed refreshes should not take the
                 * layer off the map; the stations have not moved.
                 */
                Cache::put(
                    $this->key(),
                    $stations,
                    now()->addHours(max(1, (int) config('visitdasol.map.fuel_cache_hours', 168)))
                );

                return $stations;
            }
        }

        return null;
    }

    /**
     * The box is part of the key, so an office that tightens it is not
     * served the old answer for the old box.
     */
    private function key(): string
    {
        return 'visitdasol.fuel.'.md5(json_encode(
            config('visitdasol.map.fuel_bounds')
        ));
    }

    /**
     * Null when the service could not be reached or refused, which is
     * different from an empty list: a corner of the map with no filling
     * stations is an answer, and should not be retried for a week.
     *
     * @param  array<string, float|int|string>  $bounds
     * @return list<array{id: string, name: string, brand: ?string, lat: float, lng: float}>|null
     */
    private function ask(string $url, array $bounds): ?array
    {
        $box = implode(',', [
            (float) ($bounds['south'] ?? 0),
            (float) ($bounds['west'] ?? 0),
            (float) ($bounds['north'] ?? 0),
            (float) ($bounds['east'] ?? 0),
        ]);

        /*
         * Ways as well as nodes: a station mapped as a forecourt outline
         * rather than a single point is just as real, and "out center"
         * hands back a point for it either way.
         */
        $query = <<<OVERPASS
            [out:json][timeout:25];
            (
              node["amenity"="fuel"]({$box});
              way["amenity"="fuel"]({$box});
            );
            out center tags;
            OVERPASS;

        try {
            $response = Http::withHeaders([
                // Required by the service. A caller it cannot identify is
                // a caller it is entitled to refuse, and it does: the
                // request comes back 406 without this.
                'User-Agent' => config('app.name').' ('.config('app.url').')',
                'Accept' => 'application/json',
            ])
                /*
                 * Generous, because nobody is waiting: this runs from a
                 * scheduled command, not from a page. Overpass routinely
                 * takes ten seconds or more on a first ask, and cutting
                 * it off early was turning a working answer into none.
                 */
                ->timeout(45)
                ->asForm()
                ->post($url, ['data' => $query]);

            if ($response->failed()) {
                return null;
            }

            $elements = $response->json('elements');

            // A body with no elements key at all is not an answer; it is
            // usually an error page the service returned with a 200.
            return is_array($elements) ? $this->stations($elements) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $elements
     * @return list<array{id: string, name: string, brand: ?string, lat: float, lng: float}>
     */
    private function stations(array $elements): array
    {
        $stations = [];

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            // A node carries its own point; a forecourt outline carries a
            // computed centre instead.
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lng = $element['lon'] ?? ($element['center']['lon'] ?? null);

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];

            $brand = $this->text($tags['brand'] ?? null)
                ?? $this->text($tags['operator'] ?? null);

            /*
             * Plenty of these are tagged simply "Gas", or with no name at
             * all. Falling back to the brand, and then to a plain label,
             * beats a popup headed by an empty line.
             */
            $name = $this->text($tags['name'] ?? null)
                ?? $brand
                ?? 'Filling station';

            $stations[] = [
                'id' => 'fuel-'.($element['type'] ?? 'node').'-'.($element['id'] ?? count($stations)),
                'name' => $name,
                // Not repeated when it is already the name.
                'brand' => $brand !== null && $brand !== $name ? $brand : null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }

        return $stations;
    }

    /** A tag worth showing, or null for one that is blank or absurd. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish($value);

        return ($value === '' || mb_strlen($value) > 80) ? null : $value;
    }
}
