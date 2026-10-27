<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * How much a road climbs and how much it drops.
 *
 * OSRM answers how long a route is and how long it takes, but its geometry
 * carries only longitude and latitude — the height is simply not in it. On
 * roads around Dasol that is a real gap: most visitors arrive by tricycle or
 * motorcycle, and a steep climb matters to both in a way it does not to a car.
 *
 * Heights come from a free public service, asked from the SERVER for the same
 * reasons as PlaceFinder: one identifiable caller, answers cached and shared,
 * and a page that only ever talks to this site.
 *
 * Nothing here may break a route. Every failure returns null and the map keeps
 * the distance and the driving time it already had.
 */
class Elevation
{
    /** Points per request. The service accepts 100; this leaves room. */
    private const BATCH = 90;

    /** A point rounded to 4 decimals is about 11 m across. */
    private const GRID = 4;

    /**
     * Ignore rises and falls smaller than this.
     *
     * The height model is a satellite one, good to a few metres vertically,
     * and consecutive samples a couple of hundred metres apart carry
     * INDEPENDENT error. Summed without thought, ninety samples of ±3 m
     * noise can manufacture a couple of hundred metres of climbing out of a
     * flat coastal road — which is roughly what a first run of this reported
     * for the Poblacion road before the smoothing below was added.
     */
    private const NOISE_METRES = 5.0;

    /**
     * The steepest claim needs a long enough run to stand on.
     *
     * Over 50 m, three metres of model error is a six per cent gradient that
     * nobody ever drove. OSRM emits points unevenly — dense at junctions —
     * so short runs do turn up, and this is what keeps them out of a number
     * presented to a visitor as fact.
     */
    private const STEEPEST_RUN_METRES = 150.0;

    /**
     * Shortest stretch worth colouring on the map.
     *
     * Lower than the bar for the single "steepest" figure, because a colour
     * is a hint and that number is a claim, but still long enough that model
     * error cannot paint a climb onto a flat road.
     */
    private const GRADE_RUN_METRES = 80.0;

    private const EARTH_RADIUS_M = 6371000;

    /**
     * The height profile of a path, or null if it cannot be worked out.
     *
     * @param  list<array{lat: float, lng: float}>  $path
     * @return array{points: list<array{lat: float, lng: float, m: float, at: float, grade: float|null}>, ascent: int, descent: int, low: int, high: int, steepest: float|null, steepest_falls: bool}|null
     */
    public function forPath(array $path): ?array
    {
        if (count($path) < 2 || blank(config('visitdasol.map.elevation_url'))) {
            return null;
        }

        $heights = $this->heights($path);

        if ($heights === null) {
            return null;
        }

        return $this->profile($path, $heights);
    }

    /**
     * Height per point, in the order given.
     *
     * Cached one point at a time on a rounded grid rather than one route at a
     * time: two routes out of the same town share most of their length, and a
     * point already known costs nothing to reuse. Only the misses are asked
     * for, in batches.
     *
     * @param  list<array{lat: float, lng: float}>  $path
     * @return list<float>|null
     */
    private function heights(array $path): ?array
    {
        $found = [];
        $missing = [];

        foreach ($path as $index => $point) {
            $key = $this->key($point);
            $cached = Cache::get($key);

            if ($cached !== null) {
                $found[$index] = (float) $cached;
            } else {
                $missing[$index] = $point;
            }
        }

        foreach (array_chunk($missing, self::BATCH, true) as $batch) {
            $answered = $this->ask($batch);

            if ($answered === null) {
                // A batch that fails sinks the whole profile: a line with a
                // hole in it would read as a cliff that is not there.
                return null;
            }

            foreach ($answered as $index => $metres) {
                $found[$index] = $metres;
                Cache::put($this->key($path[$index]), $metres, now()->addDays(90));
            }
        }

        ksort($found);

        return count($found) === count($path) ? array_values($found) : null;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $batch
     * @return array<int, float>|null
     */
    private function ask(array $batch): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('app.name').' ('.config('app.url').')',
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->retry(1, 300, fn (Throwable $e): bool => $e instanceof ConnectionException, false)
                ->get(config('visitdasol.map.elevation_url'), [
                    'latitude' => implode(',', array_map(fn ($p) => round($p['lat'], self::GRID), $batch)),
                    'longitude' => implode(',', array_map(fn ($p) => round($p['lng'], self::GRID), $batch)),
                ]);

            if ($response->failed()) {
                return null;
            }

            $metres = $response->json('elevation');

            if (! is_array($metres) || count($metres) !== count($batch)) {
                return null;
            }

            // Back onto the original indexes, which array_chunk preserved.
            return array_combine(array_keys($batch), array_map(fn ($m) => (float) $m, $metres));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $path
     * @param  list<float>  $heights
     * @return array{points: list<array{lat: float, lng: float, m: float, at: float, grade: float|null}>, ascent: int, descent: int, low: int, high: int, steepest: float|null, steepest_falls: bool}
     */
    private function profile(array $path, array $heights): array
    {
        $smooth = $this->smoothed($heights);

        $points = [];
        $along = 0.0;

        foreach ($path as $index => $point) {
            $run = $index > 0 ? $this->metresBetween($path[$index - 1], $point) : 0.0;
            $along += $run;

            /*
             * The slope of the stretch that ENDS at this point, as a signed
             * percentage, so the map can colour the road by it.
             *
             * From the smoothed series and only over a run long enough to
             * mean something: on a short hop, a few metres of model error is
             * a gradient nobody ever drove, and it would paint a scarlet
             * climb across a flat coastal road.
             */
            $grade = null;

            if ($index > 0 && $run >= self::GRADE_RUN_METRES) {
                $rise = $smooth[$index] - $smooth[$index - 1];

                if (abs($rise) >= self::NOISE_METRES) {
                    $grade = round($rise / $run * 100, 1);
                }
            }

            $points[] = [
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'm' => $heights[$index],
                'at' => round($along),
                'grade' => $grade,
            ];
        }

        /*
         * Ascent and descent, with the wobble filtered out TWICE.
         *
         * First the series is smoothed, because neighbouring samples carry
         * independent model error and averaging three of them cancels most of
         * it. Then the running total only lands in the answer once it has
         * moved far enough to be real; anything smaller is left pending and
         * may be cancelled by the next step.
         *
         * The smoothed series is also what the per-point grades above were
         * read from, so the colour of the road and the totals beneath it can
         * never tell the visitor two different stories. The highest and
         * lowest points reported come from the real readings, because
         * averaging would shave the top off a genuine summit.
         */
        $ascent = 0.0;
        $descent = 0.0;
        $pending = 0.0;

        for ($i = 1; $i < count($smooth); $i++) {
            $step = $smooth[$i] - $smooth[$i - 1];

            // Direction changed: whatever was pending was noise.
            if ($pending !== 0.0 && ($step > 0) !== ($pending > 0)) {
                $pending = 0.0;
            }

            $pending += $step;

            if (abs($pending) >= self::NOISE_METRES) {
                $pending > 0 ? $ascent += $pending : $descent += abs($pending);
                $pending = 0.0;
            }
        }

        // The steepest stretch, measured over each step rather than between
        // neighbouring samples, which at this spacing is where noise lives.
        $steepest = null;
        $steepestFalls = false;

        for ($i = 1; $i < count($points); $i++) {
            $run = $points[$i]['at'] - $points[$i - 1]['at'];
            $rise = $smooth[$i] - $smooth[$i - 1];

            if ($run < self::STEEPEST_RUN_METRES || abs($rise) < self::NOISE_METRES) {
                continue;
            }

            $grade = abs($rise) / $run * 100;

            if ($steepest === null || $grade > $steepest) {
                $steepest = $grade;
                $steepestFalls = $rise < 0;
            }
        }

        return [
            'points' => $points,
            'ascent' => (int) round($ascent),
            'descent' => (int) round($descent),
            'low' => (int) round(min($heights)),
            'high' => (int) round(max($heights)),
            'steepest' => $steepest === null ? null : round($steepest, 1),
            'steepest_falls' => $steepestFalls,
        ];
    }

    /**
     * A three-point moving average, with the ends left where they are.
     *
     * Neighbouring samples carry independent model error, so averaging them
     * cancels most of it while leaving a real slope — which runs across many
     * samples — very nearly intact.
     *
     * @param  list<float>  $heights
     * @return list<float>
     */
    private function smoothed(array $heights): array
    {
        $count = count($heights);

        if ($count < 3) {
            return $heights;
        }

        $out = [$heights[0]];

        for ($i = 1; $i < $count - 1; $i++) {
            $out[] = ($heights[$i - 1] + $heights[$i] + $heights[$i + 1]) / 3;
        }

        $out[] = $heights[$count - 1];

        return $out;
    }

    /** @param array{lat: float, lng: float} $from */
    private function metresBetween(array $from, array $to): float
    {
        $toRad = M_PI / 180;
        $dLat = ($to['lat'] - $from['lat']) * $toRad;
        $dLng = ($to['lng'] - $from['lng']) * $toRad;

        $a = sin($dLat / 2) ** 2
            + cos($from['lat'] * $toRad) * cos($to['lat'] * $toRad) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array{lat: float, lng: float} $point */
    private function key(array $point): string
    {
        return 'visitdasol.height.'
            .number_format($point['lat'], self::GRID, '.', '').','
            .number_format($point['lng'], self::GRID, '.', '');
    }
}
