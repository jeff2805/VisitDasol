<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The 18 barangays of Dasol, as points on a map.
 *
 * Read from config('visitdasol.barangay_points'), which records where each
 * number came from. A barangay with no confirmed point is left off the map
 * rather than guessed at: on a municipal site a wrong pin is worse than no
 * pin, because a visitor has no way of telling the two apart.
 */
class BarangayPoints
{
    /**
     * Barangays that can actually be drawn.
     *
     * Each carries the landmark its point was taken from, where there is one.
     * That is the honest answer to "where exactly is this?": the pin sits at
     * Tambobong Elementary School, and saying so is both more truthful and
     * more useful than the word "approximate" on its own — a visitor can
     * actually navigate to a school.
     *
     * @return Collection<int, array{id: string, name: string, lat: float, lng: float, approximate: bool, landmark: ?string}>
     */
    public function mappable(): Collection
    {
        return collect(config('visitdasol.barangay_points', []))
            ->filter(fn ($point) => is_array($point) && isset($point['lat'], $point['lng']))
            ->map(fn (array $point, string $name) => [
                // Namespaced, so it cannot collide with a resort or spot id
                // in the trip planner's two menus.
                'id' => 'barangay-'.Str::slug($name),
                'name' => $name,
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
                // Anything the office has not confirmed itself is shown as a
                // guide, not as a location.
                'approximate' => ($point['source'] ?? null) !== 'office'
                    && ($point['source'] ?? null) !== 'place',
                'landmark' => $point['landmark'] ?? null,
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * Barangays with no point yet, so the office can see what is missing
     * instead of wondering why the map looks short.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return collect(config('visitdasol.barangay_points', []))
            ->reject(fn ($point) => is_array($point) && isset($point['lat'], $point['lng']))
            ->keys()
            ->sort()
            ->values()
            ->all();
    }
}
