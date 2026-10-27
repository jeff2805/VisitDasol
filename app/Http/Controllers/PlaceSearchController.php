<?php

namespace App\Http\Controllers;

use App\Services\PlaceFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Looks up a place the visitor typed into the map's "From" box.
 *
 * Public on purpose: the map is public, and planning a trip should not need
 * an account. It is throttled in the route, answers are cached in
 * PlaceFinder, and the only thing it ever returns is a name and two numbers.
 */
class PlaceSearchController extends Controller
{
    public function __invoke(Request $request, PlaceFinder $places): JsonResponse
    {
        $query = $request->query('q');

        if (! is_string($query) || trim($query) === '') {
            return response()->json(['found' => false], 422);
        }

        $place = $places->find($query);

        if ($place === null) {
            // 200, not 404: the request was fine, the answer is simply "no
            // such place". A 404 here would look like a broken route in the
            // browser's console on every typo.
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true] + $place);
    }
}
