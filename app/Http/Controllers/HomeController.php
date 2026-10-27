<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Destination;
use App\Models\Resort;
use Illuminate\Contracts\View\View;

/**
 * The public home page. Every query below is limited to content the
 * Municipal Tourism Office has approved and published.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $resorts = Resort::publiclyVisible()
            ->with('amenities')
            ->withMin(['rooms as starting_price' => fn ($query) => $query->active()], 'price_per_night')
            ->orderByDesc('is_featured')
            /*
             * A listing with a photo goes first. This strip is the shop
             * window, and a grey "No photo yet" box is not what it is for;
             * before this, ordering was alphabetical, so whether a resort
             * had ever uploaded anything made no difference to whether it
             * led the page.
             */
            ->orderByRaw("CASE WHEN main_image IS NULL OR main_image = '' THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->limit(3)
            ->get();

        $destinations = Destination::active()
            ->with('category')
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit(3)
            ->get();

        return view('site.home', [
            'resorts' => $resorts,
            'destinations' => $destinations,
            'announcements' => Announcement::published()
                ->orderByDesc('is_featured')
                ->latest('published_at')
                ->limit(2)
                ->get(),
            /*
             * A small, honest preview of the full map, built from the same
             * $resorts and $destinations already fetched above for the cards —
             * latitude and longitude ride along on those rows for free, so this
             * costs no query of its own and cannot drift out of step with the
             * featured listings shown just above it.
             *
             * The full map (every listing, every barangay, satellite view, the
             * trip planner) stays the one place that experience lives — this is
             * a reason to click through to it, not a second copy of it.
             */
            'mapPreview' => $resorts->map(fn (Resort $resort) => [
                'id' => 'resort-'.$resort->id,
                'type' => 'resort',
                'name' => $resort->name,
                'subtitle' => 'Brgy. '.$resort->barangay,
                'lat' => (float) $resort->latitude,
                'lng' => (float) $resort->longitude,
                'image' => $resort->main_image_url,
                'url' => route('resorts.show', $resort),
            ])->concat($destinations->map(fn (Destination $destination) => [
                'id' => 'destination-'.$destination->id,
                'type' => 'destination',
                'name' => $destination->name,
                'subtitle' => $destination->category?->name,
                'lat' => (float) $destination->latitude,
                'lng' => (float) $destination->longitude,
                'image' => $destination->main_image_url,
                'url' => route('destinations.show', $destination),
            ]))->values(),
        ]);
    }
}
