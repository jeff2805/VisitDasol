<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

/**
 * Starter amenity list. The MTO can edit it later (Phase 9).
 * Existing names are left untouched, so re-running is safe.
 */
class AmenitySeeder extends Seeder
{
    /** name => Bootstrap Icons name */
    public const AMENITIES = [
        'Beachfront' => 'water',
        'Swimming pool' => 'droplet',
        'Wi-Fi' => 'wifi',
        'Air-conditioned rooms' => 'snow',
        'Parking' => 'p-square',
        'Restaurant' => 'cup-hot',
        'Cottages' => 'house-door',
        'Function hall' => 'people',
        'Videoke' => 'mic',
        'Kayak rental' => 'life-preserver',
        'Showers and changing rooms' => 'droplet-half',
        'Grilling area' => 'fire',
        'Pet-friendly' => 'heart',
        'Wheelchair accessible' => 'universal-access',
        'First aid station' => 'bandaid',
        'Security and CCTV' => 'shield-check',
        'Backup power' => 'plug',
    ];

    public function run(): void
    {
        foreach (self::AMENITIES as $name => $icon) {
            Amenity::query()->firstOrCreate(['name' => $name], ['icon' => $icon, 'is_active' => true]);
        }
    }
}
