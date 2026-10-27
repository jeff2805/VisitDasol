<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns something a visitor typed — "Alaminos", "Dagupan City", "Zambales
 * Highway" — into a point on the map.
 *
 * Asked from the server, never from the browser. Three reasons, in order of
 * how much they matter:
 *
 *   1. OpenStreetMap's search asks every caller to identify itself in a
 *      User-Agent. A browser cannot set one, so a request made from the page
 *      is an anonymous request against somebody else's free service.
 *   2. Answers can be cached here and shared between every visitor. Most
 *      people planning a trip to Dasol start from the same dozen towns.
 *   3. The page then only ever talks to this site, so the Content Security
 *      Policy needs no hole opened in it.
 *
 * Nothing here is allowed to break the map. Every failure returns null and
 * the caller says it could not find the place.
 */
class PlaceFinder
{
    /** Long, because a town does not move. */
    private const CACHE_DAYS = 30;

    /**
     * @return array{name: string, lat: float, lng: float}|null
     */
    public function find(string $query): ?array
    {
        $query = Str::squish($query);

        if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            return null;
        }

        $key = 'visitdasol.place.'.md5(mb_strtolower($query));

        /*
         * A miss is cached too, as a sentinel rather than null: without it,
         * the same unanswerable spelling goes back to OpenStreetMap on every
         * single attempt, which is exactly the behaviour their terms ask
         * callers not to have.
         */
        $cached = Cache::remember($key, now()->addDays(self::CACHE_DAYS), function () use ($query) {
            return $this->ask($query) ?? 'none';
        });

        return is_array($cached) ? $cached : null;
    }

    /**
     * @return array{name: string, lat: float, lng: float}|null
     */
    private function ask(string $query): ?array
    {
        $url = config('visitdasol.map.search_url');

        if (blank($url)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                // Required by the service. A caller it cannot identify is a
                // caller it is entitled to refuse.
                'User-Agent' => config('app.name').' ('.config('app.url').')',
                'Accept' => 'application/json',
            ])
                ->timeout(8)
                ->retry(1, 300, fn (Throwable $e): bool => $e instanceof ConnectionException, false)
                ->get($url, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    // The Philippines only, and ranked towards this corner of
                    // Luzon, so "San Vicente" finds the one a visitor to Dasol
                    // is likely to mean out of the dozens in the country.
                    'countrycodes' => 'ph',
                    'viewbox' => '119.40,16.80,121.20,15.20',
                ]);

            if ($response->failed()) {
                return null;
            }

            $hit = $response->json('0');

            if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
                return null;
            }

            return [
                // The first part of the full address: "Alaminos, Pangasinan,
                // Ilocos Region, 2404, Philippines" is more than a label needs.
                'name' => Str::of((string) ($hit['display_name'] ?? $query))
                    ->explode(',')
                    ->take(2)
                    ->map(fn (string $part) => trim($part))
                    ->implode(', '),
                'lat' => (float) $hit['lat'],
                'lng' => (float) $hit['lon'],
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
