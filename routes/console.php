<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled housekeeping
|--------------------------------------------------------------------------
|
| NOTHING BELOW RUNS ON ITS OWN. Laravel's scheduler needs something to wake
| it once a minute:
|
|   while developing   php artisan schedule:work
|   on a Linux server  * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
|   on Windows         a Task Scheduler entry running: php artisan schedule:run
|
| Both jobs are safe to run by hand at any time, and both can be rehearsed:
|
|   php artisan visitdasol:purge-codes
|   php artisan visitdasol:complete-stays --dry-run
|
*/

// Expired one-time codes are dead credentials; they should not pile up.
Schedule::command('visitdasol:purge-codes')
    ->dailyAt('03:10')
    ->withoutOverlapping();

/*
| A confirmed stay whose checkout hour has passed would otherwise read
| "Confirmed" for ever and keep inflating the "upcoming stays" figure.
|
| HOURLY, not nightly. Guests check out through the day, and a stay that
| ended at noon should not still be reading "Confirmed" that evening
| waiting for a job that runs at twenty past three in the morning.
*/
Schedule::command('visitdasol:complete-stays')
    ->hourly()
    ->withoutOverlapping();

/*
| Dasol's filling stations, from OpenStreetMap, for the public map.
|
| Weekly, in the small hours. Petrol stations do not move, Overpass is a
| free service that should not be asked often, and the stored answer
| outlives several missed refreshes — so a busy night at Overpass never
| takes the layer off the map.
|
| Run it by hand once after setting up, or the map shows none:
|   php artisan visitdasol:refresh-fuel
*/
Schedule::command('visitdasol:refresh-fuel')
    ->weeklyOn(1, '03:40')
    ->withoutOverlapping();
