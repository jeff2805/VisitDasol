<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * The shapes of Dasol's barangays, for the map's boundary layer.
 *
 * Read from a GeoJSON file the tourism office supplies. NOT drawn here
 * and not fetched from anywhere: a barangay boundary is a legal line,
 * and the project already refuses to guess a single point
 * (see BarangayPoints) — guessing eighteen outlines would be far worse.
 *
 * OpenStreetMap cannot supply these. It has no boundary for Dasol at
 * all, municipal or barangay; the only barangay outlines anywhere near
 * are Infanta's. So the file has to come from the office, from NAMRIA,
 * or from the PSA's published boundaries.
 *
 * Until it exists the map falls back to the name labels it already
 * draws, which is why nothing here throws when the file is missing.
 */
class BarangayBoundaries
{
    /**
     * The file the office drops in. Kept out of the database on purpose:
     * it is a static reference dataset, it is replaced wholesale when the
     * boundaries are revised, and keeping it in the repository means a
     * deployment carries the same map as the machine it was tested on.
     */
    public const PATH = 'app/geo/dasol-barangays.geojson';

    public function path(): string
    {
        return storage_path(self::PATH);
    }

    public function exists(): bool
    {
        return File::exists($this->path());
    }

    /**
     * The boundaries as a FeatureCollection the browser can draw, or null
     * when there is no usable file.
     *
     * Every feature is given a 'name' the map can label and match against
     * the barangay list, and anything that is not one of Dasol's own
     * eighteen is dropped: a file covering the whole province would
     * otherwise put the neighbours' outlines on Dasol's map.
     *
     * @return array{type: string, features: list<array<string, mixed>>}|null
     */
    public function featureCollection(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        try {
            $raw = json_decode(File::get($this->path()), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // A malformed file must not take the map down with it.
            return null;
        }

        if (! is_array($raw) || ! is_array($raw['features'] ?? null)) {
            return null;
        }

        $known = $this->known();
        $features = [];

        foreach ($raw['features'] as $feature) {
            if (! is_array($feature) || ! is_array($feature['geometry'] ?? null)) {
                continue;
            }

            $name = $this->nameOf($feature, $known);

            if ($name === null) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $feature['geometry'],
                'properties' => [
                    'name' => $name,
                    // The same id the point labels and the trip planner
                    // use, so selecting a barangay anywhere on the page
                    // can find its outline.
                    'id' => 'barangay-'.Str::slug($name),
                ],
            ];
        }

        return $features === []
            ? null
            : ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * Which of Dasol's barangays the supplied file actually covers, and
     * which it does not, so the office can see what is still missing
     * rather than wondering why the map looks patchy.
     *
     * @return array{present: list<string>, missing: list<string>}
     */
    public function coverage(): array
    {
        $drawn = collect($this->featureCollection()['features'] ?? [])
            ->pluck('properties.name')
            ->unique();

        return [
            'present' => $drawn->sort()->values()->all(),
            'missing' => collect($this->known())
                ->reject(fn (string $name) => $drawn->contains($name))
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * The barangay this feature is for, matched against Dasol's own list,
     * or null for a shape belonging to somebody else.
     *
     * Several property names are accepted because every source spells it
     * differently: NAMRIA, the PSA and OpenStreetMap exports disagree,
     * and an office should not have to rewrite a file to use it.
     *
     * @param  array<string, mixed>  $feature
     * @param  list<string>  $known
     */
    private function nameOf(array $feature, array $known): ?string
    {
        $properties = is_array($feature['properties'] ?? null)
            ? $feature['properties']
            : [];

        foreach (['name', 'NAME', 'barangay', 'BARANGAY', 'ADM4_EN', 'NAME_3', 'brgy'] as $key) {
            $value = $properties[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $candidate = Str::squish($value);

            /*
             * Matched case- and accent-insensitively: a file is as likely
             * to say "OSMENA" or "Osmena" as "Osmeña", and refusing the
             * first two would quietly drop a real barangay.
             */
            foreach ($known as $name) {
                if ($this->same($candidate, $name)) {
                    // The config's spelling wins, so the label on the map
                    // matches the one in every menu beside it.
                    return $name;
                }
            }
        }

        return null;
    }

    private function same(string $a, string $b): bool
    {
        return Str::slug($a) === Str::slug($b);
    }

    /**
     * @return list<string>
     */
    private function known(): array
    {
        return array_values(array_filter(
            (array) config('visitdasol.barangays', []),
            'is_string'
        ));
    }
}
