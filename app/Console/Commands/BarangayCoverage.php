<?php

namespace App\Console\Commands;

use App\Services\BarangayBoundaries;
use Illuminate\Console\Command;

/**
 * Which of Dasol's 18 barangays the boundary file actually covers.
 *
 * A partial file is the likely case — a province-wide download may spell
 * a barangay differently, or miss one entirely — and a map that is
 * quietly short of three outlines looks finished. This says so.
 */
class BarangayCoverage extends Command
{
    protected $signature = 'visitdasol:barangay-coverage';

    protected $description = 'Report which barangay boundaries the map can draw';

    public function handle(BarangayBoundaries $boundaries): int
    {
        if (! $boundaries->exists()) {
            $this->warn('No boundary file yet.');
            $this->line('  Expected at: '.$boundaries->path());
            $this->line('  See storage/app/geo/README.md for where to get one.');
            $this->newLine();
            $this->info('The map is drawing barangay name labels instead.');

            return self::SUCCESS;
        }

        $coverage = $boundaries->coverage();

        if ($coverage['present'] === []) {
            $this->error('The file was read, but none of its shapes matched a Dasol barangay.');
            $this->line('  Check the property holding the name, and that the file is WGS84.');
            $this->line('  See storage/app/geo/README.md.');

            return self::FAILURE;
        }

        $this->info(count($coverage['present']).' of 18 barangays have an outline:');

        foreach ($coverage['present'] as $name) {
            $this->line('  <fg=green>✓</> '.$name);
        }

        if ($coverage['missing'] === []) {
            $this->newLine();
            $this->info('All 18 are covered.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(count($coverage['missing']).' still missing, and drawn as name labels only:');

        foreach ($coverage['missing'] as $name) {
            $this->line('  <fg=yellow>·</> '.$name);
        }

        /*
         * A warning and a SUCCESS code. A partial file is a normal stage
         * of getting this data together, not a broken deployment, and a
         * failing exit would make it look like one.
         */
        return self::SUCCESS;
    }
}
