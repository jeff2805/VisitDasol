<?php

namespace App\Console\Commands;

use App\Services\FuelStations;
use Illuminate\Console\Command;

/**
 * Fetches Dasol's filling stations from OpenStreetMap and stores them
 * for the map to read.
 *
 * Done here rather than when a visitor opens the map, because Overpass
 * is a free service that routinely takes ten seconds or answers 502 when
 * its queue is full. Nobody should wait on that to see where the resorts
 * are.
 *
 * Safe to run at any time, and worth running by hand once after setting
 * up: until it has run, the map simply shows no filling stations.
 */
class RefreshFuelStations extends Command
{
    protected $signature = 'visitdasol:refresh-fuel';

    protected $description = 'Fetch Dasol filling stations from OpenStreetMap for the public map';

    public function handle(FuelStations $fuel): int
    {
        $bounds = config('visitdasol.map.fuel_bounds');

        $this->info('Asking OpenStreetMap for filling stations inside '
            .implode(', ', array_map(
                fn ($key) => $key.' '.($bounds[$key] ?? '?'),
                ['south', 'west', 'north', 'east']
            )).'…');

        $stations = $fuel->refresh();

        if ($stations === null) {
            /*
             * A warning and a SUCCESS exit code. Overpass being busy is
             * an ordinary event, not a broken deployment, and whatever
             * was stored before is still on the map. A failing exit here
             * would have the scheduler reporting an outage every time a
             * free service had a busy afternoon.
             */
            $this->warn('No Overpass mirror answered. The stations already stored are unchanged.');

            return self::SUCCESS;
        }

        if ($stations === []) {
            $this->warn('OpenStreetMap has no filling stations mapped inside that box.');

            return self::SUCCESS;
        }

        foreach ($stations as $station) {
            $this->line(sprintf(
                '  %s, %s  %s',
                $station['lat'],
                $station['lng'],
                $station['name'].($station['brand'] ? ' ('.$station['brand'].')' : '')
            ));
        }

        $this->info('Stored '.count($stations).' filling '
            .str('station')->plural(count($stations)).'.');

        return self::SUCCESS;
    }
}
