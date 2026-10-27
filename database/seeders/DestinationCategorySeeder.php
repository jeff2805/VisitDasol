<?php

namespace Database\Seeders;

use App\Models\DestinationCategory;
use Illuminate\Database\Seeder;

/**
 * Destination categories from the project requirements.
 * Existing names are left untouched, so re-running is safe.
 */
class DestinationCategorySeeder extends Seeder
{
    /** name => Bootstrap Icons name */
    public const CATEGORIES = [
        'Beach' => 'umbrella',
        'Island' => 'tsunami',
        'Natural Attraction' => 'tree',
        'Historical Site' => 'bank',
        'Viewpoint' => 'binoculars',
        'Recreation' => 'bicycle',
        'Other' => 'geo-alt',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $name => $icon) {
            DestinationCategory::query()->firstOrCreate(['name' => $name], ['icon' => $icon]);
        }
    }
}
