<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed
 *
 * Always:   the MTO account, amenities, destination categories.
 * Optional: fictional demo data when SEED_DEMO_DATA=true (never in production).
 *
 * WithoutModelEvents is intentionally NOT used: resorts, destinations,
 * categories, and announcements generate their slugs in a model event.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SuperAdminSeeder::class,
            AmenitySeeder::class,
            DestinationCategorySeeder::class,
        ]);

        if (! config('visitdasol.demo.enabled')) {
            $this->command?->info('Demo data skipped (SEED_DEMO_DATA is not true).');

            return;
        }

        if (app()->isProduction()) {
            $this->command?->warn('Demo data skipped: APP_ENV is production.');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
