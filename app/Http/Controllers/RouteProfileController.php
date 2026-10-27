<?php

namespace App\Http\Controllers;

use App\Http\Requests\RouteProfileRequest;
use App\Services\Elevation;
use Illuminate\Http\JsonResponse;

/**
 * Whether a route climbs or drops, and by how much.
 *
 * Public, like the map. Throttled in the route, cached per point in
 * Elevation, and it returns nothing but heights along the line it was given.
 */
class RouteProfileController extends Controller
{
    public function __invoke(RouteProfileRequest $request, Elevation $elevation): JsonResponse
    {
        $profile = $elevation->forPath($request->path());

        if ($profile === null) {
            // 200, not an error: the route itself is fine and already drawn.
            // Only the height could not be found, and the map says so rather
            // than making the whole thing look broken.
            return response()->json(['found' => false]);
        }

        return response()->json(['found' => true] + $profile);
    }
}
