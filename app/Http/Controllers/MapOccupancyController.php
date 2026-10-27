<?php

namespace App\Http\Controllers;

use App\Http\Requests\MapOccupancyRequest;
use App\Models\Resort;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;

/**
 * How full each resort on the public map is, for a given set of nights.
 *
 * Public, because the map is: a visitor deciding where to stay should not
 * need an account to see whether anywhere has room. It is throttled in the
 * route, and what it returns is deliberately thin — counts and a status
 * word per resort, nothing that could identify a guest, name a
 * reservation, or hint at what a resort earns.
 *
 * The figures here are a snapshot for display and nothing more. Rooms are
 * not held, and BookingService re-checks availability under a row lock
 * when a reservation is actually submitted, so a stale map can never
 * oversell a resort.
 */
class MapOccupancyController extends Controller
{
    public function __invoke(
        MapOccupancyRequest $request,
        AvailabilityService $availability
    ): JsonResponse {
        [$checkIn, $checkOut, $chosen] = $request->range();

        $resorts = self::mappable()->get();

        $figures = $availability->occupancyForResorts(
            $resorts,
            $checkIn,
            $checkOut
        );

        return response()->json([
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => (int) $checkIn->diffInDays($checkOut),

            /*
             * Whether these are tonight's figures or a forecast. The map
             * says one or the other out loud: "staying tonight" and "booked
             * for those nights" are different claims, and a visitor acting
             * on the wrong one turns up to a full resort.
             */
            'projected' => $chosen,

            'resorts' => $resorts
                ->map(fn (Resort $resort) => [
                    'id' => 'resort-'.$resort->id,
                ] + self::blank($figures[$resort->id] ?? null))
                ->values(),
        ]);
    }

    /**
     * The resorts the public map may report on: approved, visible, in this
     * municipality, and actually placed somewhere.
     *
     * A listing with no coordinates has no pin to colour, so it is left out
     * here rather than being sent to the browser as a resort at 0, 0 in the
     * Gulf of Guinea.
     */
    public static function mappable(): \Illuminate\Database\Eloquent\Builder
    {
        return Resort::publiclyVisible()
            ->where('municipality', config('visitdasol.municipality'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select(['id', 'name', 'slug', 'barangay', 'latitude', 'longitude', 'main_image'])
            ->orderBy('name');
    }

    /**
     * A resort the service could not work out is reported as unknown
     * rather than as empty, so its pin greys out instead of inviting a
     * visitor to a place that may have no room at all.
     *
     * @param  array<string, mixed>|null  $figures
     * @return array<string, mixed>
     */
    private static function blank(?array $figures): array
    {
        return $figures ?? [
            'capacity' => 0,
            'guests' => 0,
            'remaining' => 0,
            'percent' => 0,
            'rooms_total' => 0,
            'rooms_free' => 0,
            'status' => 'unknown',
        ];
    }
}
