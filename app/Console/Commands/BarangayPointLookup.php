<?php

namespace App\Console\Commands;

use App\Services\BarangayPoints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks for the barangays that have no point on the map yet.
 *
 * WRITES NOTHING. It asks OpenStreetMap what it holds, checks each
 * answer by asking back which barangay that point falls in, and prints
 * what survives. A person then decides what goes into config, because a
 * barangay pin on a municipal site is the sort of thing that gets
 * printed on a tarpaulin.
 *
 * The checking is the whole point. Nominatim will happily answer a
 * search for "Hermosa, Dasol" with a school whose address says only
 * "Dasol" — it matched something, somewhere, and returning it is not a
 * claim about Hermosa. Taking that at face value is exactly how a wrong
 * pin gets onto a map and stays there.
 */
class BarangayPointLookup extends Command
{
    protected $signature = 'visitdasol:barangay-points
                            {--all : Check every barangay, not only the ones with no point}';

    protected $description = 'Look up barangays that have no point yet, and report verified candidates';

    /**
     * Nominatim asks for no more than one request a second and for
     * callers to identify themselves. Two requests are made per
     * barangay — a search and a check — so this waits between both.
     */
    private const PAUSE_MICROSECONDS = 1_200_000;

    public function handle(BarangayPoints $points): int
    {
        $search = config('visitdasol.map.search_url');

        if (! is_string($search) || $search === '') {
            $this->error('No search service is configured (visitdasol.map.search_url).');

            return self::FAILURE;
        }

        $wanted = $this->option('all')
            ? config('visitdasol.barangays', [])
            : $points->missing();

        if ($wanted === []) {
            $this->info('Every barangay already has a point. Nothing to look up.');

            return self::SUCCESS;
        }

        $this->info('Asking OpenStreetMap about '.count($wanted).' '
            .str('barangay')->plural(count($wanted)).'.');
        $this->line('Nothing is saved. Confirmed answers are printed for a person to copy in.');
        $this->newLine();

        $confirmed = [];
        $rejected = [];

        foreach ($wanted as $barangay) {
            $found = $this->lookUp($search, $barangay);

            if ($found === null) {
                $rejected[$barangay] = 'OpenStreetMap holds nothing that it places inside this barangay.';

                $this->line('  <fg=yellow>·</> '.$barangay.' — nothing confirmed');

                continue;
            }

            $confirmed[$barangay] = $found;

            $this->line('  <fg=green>✓</> '.$barangay.' — '.$found['landmark']);
        }

        $this->newLine();

        if ($confirmed !== []) {
            $this->info('Confirmed. OpenStreetMap places each of these inside the named barangay:');
            $this->newLine();

            foreach ($confirmed as $barangay => $found) {
                $this->line(sprintf(
                    "        '%s' => ['lat' => %.7f, 'lng' => %.7f, 'source' => 'landmark', 'landmark' => '%s'],",
                    $barangay,
                    $found['lat'],
                    $found['lng'],
                    str_replace("'", "\\'", $found['landmark'])
                ));
            }

            $this->newLine();
            $this->line('Copy into barangay_points in config/visitdasol.php, after checking each one.');
        }

        if ($rejected !== []) {
            $this->newLine();
            $this->warn(count($rejected).' could not be confirmed:');

            foreach ($rejected as $barangay => $why) {
                $this->line('  <fg=yellow>·</> '.$barangay.' — '.$why);
            }

            $this->newLine();
            $this->line('These need the municipality itself: a point from the MPDO, the');
            $this->line('barangay hall\'s own coordinates, or a surveyed boundary file.');
        }

        return self::SUCCESS;
    }

    /**
     * One barangay: search, then check the best answers by asking what
     * barangay they are in. Returns null unless something survives.
     *
     * @return array{lat: float, lng: float, landmark: string}|null
     */
    private function lookUp(string $search, string $barangay): ?array
    {
        $hits = $this->fetch($search, [
            'q' => $barangay.', Dasol, Pangasinan, Philippines',
            'format' => 'json',
            'limit' => 5,
        ]);

        foreach ($hits as $hit) {
            if (! isset($hit['lat'], $hit['lon'])) {
                continue;
            }

            $lat = (float) $hit['lat'];
            $lng = (float) $hit['lon'];

            if (! $this->inDasol($lat, $lng)) {
                continue;
            }

            /*
             * THE CHECK THAT MATTERS. A search result only means the
             * service matched the words; it is not a statement about
             * which barangay the point is in. So the point is handed
             * back and the service asked what is there — and only an
             * address that names this barangay is accepted.
             */
            if ($this->barangayAt($lat, $lng) !== $this->normalise($barangay)) {
                continue;
            }

            return [
                'lat' => $lat,
                'lng' => $lng,
                'landmark' => (string) ($hit['name'] ?? $hit['display_name'] ?? 'unnamed'),
            ];
        }

        return null;
    }

    /**
     * Which barangay OpenStreetMap says a point is in, normalised, or
     * null when it names none.
     */
    private function barangayAt(float $lat, float $lng): ?string
    {
        $reverse = str_replace('/search', '/reverse', config('visitdasol.map.search_url'));

        $body = $this->fetch($reverse, [
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'zoom' => 18,
            'addressdetails' => 1,
        ]);

        $address = $body['address'] ?? null;

        if (! is_array($address)) {
            return null;
        }

        // OSM files a Philippine barangay under any of these, depending
        // on how the area was tagged.
        foreach (['village', 'suburb', 'neighbourhood', 'hamlet', 'quarter'] as $key) {
            if (isset($address[$key])) {
                return $this->normalise((string) $address[$key]);
            }
        }

        return null;
    }

    /**
     * Case and accents set aside, so "Osmeña" and "Osmena" are the same
     * barangay and a tilde cannot cost the map a pin.
     */
    private function normalise(string $name): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $folded ?: $name)));
    }

    private function inDasol(float $lat, float $lng): bool
    {
        $bounds = config('visitdasol.map.bounds');

        return $lat >= $bounds['south'] && $lat <= $bounds['north']
            && $lng >= $bounds['west'] && $lng <= $bounds['east'];
    }

    /**
     * One request, politely. A failure is reported as "nothing found"
     * rather than thrown: the service being busy is not a reason for
     * this command to fall over.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function fetch(string $url, array $query): array
    {
        usleep(self::PAUSE_MICROSECONDS);

        try {
            $response = Http::withHeaders([
                // Nominatim refuses callers that will not say who they are.
                'User-Agent' => 'VisitDasol/1.0 ('.config('app.url').')',
            ])->timeout(30)->get($url, $query);

            if (! $response->successful()) {
                return [];
            }

            $body = $response->json();

            return is_array($body) ? $body : [];
        } catch (Throwable) {
            return [];
        }
    }
}
