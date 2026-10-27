<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\Resort;
use App\Services\AvailabilityService;
use App\Services\BarangayBoundaries;
use App\Services\BarangayPoints;
use App\Services\EnvironmentalFeeService;
use App\Services\FuelStations;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * The public map of Dasol.
 *
 * Coordinates always come from the database, never from hard-coded
 * JavaScript, and only approved resorts and published destinations are
 * included. The browser receives a plain list of points; the marker
 * popups are built from text nodes, so a name containing markup is shown
 * as text and never runs.
 */
class MapController extends Controller
{
    public function __construct(
        private readonly BarangayPoints $barangays,
        private readonly AvailabilityService $availability,
        private readonly FuelStations $fuel,
        private readonly BarangayBoundaries $boundaries,
        private readonly EnvironmentalFeeService $fees,
    ) {}

    public function __invoke(): View
    {
        /*
         * Tonight: the night that starts today and ends tomorrow. The map
         * opens on "who is staying right now", and the date pickers turn
         * that into a forecast for whatever nights the visitor names.
         */
        $today = CarbonImmutable::today();

        /*
         * withRating and starting_price are for the sidebar cards, which
         * show a price and a score the way a listing does. Both are
         * subqueries on the one statement the map already runs, so the
         * dashboard costs no more trips than the old page did.
         */
        $resortModels = MapOccupancyController::mappable()
            ->withRating()
            ->withMin(
                // active(), not bookable(): the resort is already known to
                // be publicly visible here, and bookable() would re-ask
                // that question once per row.
                ['rooms as starting_price' => fn ($query) => $query->active()],
                'price_per_night'
            )
            ->get();

        $occupancy = $this->availability->occupancyForResorts(
            $resortModels,
            $today,
            $today->addDay()
        );

        $resorts = $resortModels
            ->map(fn (Resort $resort) => [
                'id' => 'resort-'.$resort->id,
                'type' => 'resort',
                'name' => $resort->name,
                'subtitle' => 'Brgy. '.$resort->barangay,
                'lat' => (float) $resort->latitude,
                'lng' => (float) $resort->longitude,
                'image' => $resort->main_image_url,
                'url' => route('resorts.show', $resort),

                // Where "Book now" goes. The resort page is where dates are
                // chosen and where availability is checked for real.
                'book_url' => route('resorts.show', $resort).'#rooms-title',

                // Used by the sidebar to filter without a round trip. The
                // bare name, not the "Brgy." label above it.
                'barangay' => $resort->barangay,

                /*
                 * The cheapest bookable room, or null when the resort has
                 * published none. Null is left as null: "from ₱0" would
                 * advertise a rate no owner has agreed to.
                 */
                'price_from' => $resort->starting_price !== null
                    ? (float) $resort->starting_price
                    : null,

                /*
                 * Only a score somebody actually left. A resort with no
                 * reviews gets no stars rather than a zero, because a zero
                 * reads as "rated badly" and not as "not rated yet".
                 */
                'rating' => $resort->rating_count > 0
                    ? round((float) $resort->rating_average, 1)
                    : null,
                'rating_count' => (int) $resort->rating_count,

                /*
                 * Drawn by the server for the first paint, so the pins carry
                 * their colours before any script runs and a visitor with
                 * JavaScript off still reads real figures.
                 */
                'occupancy' => $occupancy[$resort->id] ?? null,
            ]);

        $destinations = Destination::active()
            ->with('category:id,name')
            ->select(['id', 'category_id', 'name', 'slug', 'barangay', 'latitude', 'longitude', 'main_image'])
            ->orderBy('name')
            ->get()
            ->map(fn (Destination $destination) => [
                'id' => 'destination-'.$destination->id,
                'type' => 'destination',
                'name' => $destination->name,
                'subtitle' => $destination->category?->name
                    .($destination->barangay ? ' · Brgy. '.$destination->barangay : ''),
                'lat' => (float) $destination->latitude,
                'lng' => (float) $destination->longitude,
                'image' => $destination->main_image_url,
                'url' => route('destinations.show', $destination),
                'barangay' => $destination->barangay,
            ]);

        return view('site.map', [
            'points' => $resorts->concat($destinations)->values(),
            'resorts' => $resorts,
            'destinations' => $destinations,
            /*
             * Kept apart from $points on purpose. A barangay is somewhere to
             * get your bearings, not somewhere to be driven to, so it stays
             * out of the straight-line distances and out of the routing
             * request, which has a limit on how many places it may ask about.
             */
            'barangays' => $this->barangays->mappable(),

            /*
             * Also kept apart from $points, and for the same reason as the
             * barangays: a filling station is somewhere you pass on the
             * way, not somewhere you are going. It takes no distance
             * reading and is never sent to the routing service, which has
             * a limit on how many places it may be asked about at once.
             *
             * An empty list when OpenStreetMap cannot be reached. The
             * layer then simply does not appear.
             */
            'fuel' => $this->fuel->inDasol(),

            /*
             * The barangay outlines, when the office has supplied them.
             * Null until then, and the map keeps its name labels — the
             * shapes are legal lines and this project does not draw
             * those from memory.
             */
            'barangayShapes' => $this->boundaries->featureCollection(),

            /*
             * Environmental fee checkpoints, and ONLY while a fee is
             * actually in force.
             *
             * The module ships switched off with no rate, and a visitor
             * shown a row of toll points for a charge nobody may collect
             * would be misled about what Dasol costs. Once a fee is
             * running, saying where it is taken is the fair thing: a
             * driver should know before the barrier, not at it.
             *
             * Nothing here says what the fee is for whom. That is the
             * ordinance's business, not a map pin's.
             */
            'checkpoints' => $this->fees->isCollectable()
                ? EnvironmentalFeeCheckpoint::query()
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name', 'barangay', 'description', 'latitude', 'longitude'])
                    ->map(fn (EnvironmentalFeeCheckpoint $checkpoint) => [
                        'id' => 'checkpoint-'.$checkpoint->id,
                        'name' => $checkpoint->name,
                        'barangay' => $checkpoint->barangay,
                        'note' => $checkpoint->description,
                        'lat' => $checkpoint->latitude,
                        'lng' => $checkpoint->longitude,
                    ])
                    ->values()
                : collect(),

            'categories' => DestinationCategory::query()->orderBy('name')->pluck('name'),

            /*
             * The fee in force, for the information panel, and null while
             * none is. Read-only here in every sense: the map says what
             * the charge is and where it is taken, and offers no way to
             * pay it — municipal collections are not made from a map pin,
             * and they are never added to what a resort charges.
             */
            'environmentalFee' => $this->fees->activePolicy(),

            /*
             * What the right-hand panel knows about each barangay.
             *
             * COUNTS ONLY, AND ONLY COUNTED ONES. Land area, coastline
             * length and population are not in this system, so the panel
             * does not have a line for them: a figure invented to fill a
             * card on a municipal site is worse than a shorter card.
             */
            'barangayFacts' => $this->facts($resorts, $destinations),

            /*
             * Which of the eighteen cannot be drawn, so the panel can say
             * so plainly instead of leaving a visitor to wonder why the
             * map looks short.
             */
            'missingPoints' => $this->barangays->missing(),
        ]);
    }

    /**
     * Resorts and destinations per barangay, from the records already
     * loaded for the map. No extra queries, and no barangay invented:
     * every one of the eighteen appears, with zeroes where it has
     * nothing yet, because "none listed" is itself worth knowing.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $resorts
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $destinations
     * @return array<string, array{resorts: int, destinations: int}>
     */
    private function facts($resorts, $destinations): array
    {
        $byBarangay = fn ($rows) => collect($rows)
            ->groupBy('barangay')
            ->map(fn ($group) => $group->count());

        $resortCounts = $byBarangay($resorts);
        $spotCounts = $byBarangay($destinations);

        $facts = [];

        foreach (config('visitdasol.barangays', []) as $name) {
            $facts[$name] = [
                'resorts' => (int) $resortCounts->get($name, 0),
                'destinations' => (int) $spotCounts->get($name, 0),
            ];
        }

        return $facts;
    }
}
