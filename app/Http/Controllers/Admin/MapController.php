<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Resort;
use App\Services\BarangayPoints;
use Illuminate\Contracts\View\View;

/**
 * The office map: every resort whatever its status, plus destinations.
 * Pins are coloured by status, so pending listings and hidden ones can be
 * spotted at a glance. Visitors never see this page.
 */
class MapController extends Controller
{
    public function __construct(private readonly BarangayPoints $barangays) {}

    public function __invoke(): View
    {
        $this->authorize('viewAny', Resort::class);

        $resorts = Resort::query()
            ->with('owner:id,first_name,last_name,role,status')
            ->orderBy('name')
            ->get()
            ->map(fn (Resort $resort) => [
                'id' => 'resort-'.$resort->id,
                'type' => 'resort',
                'status' => $resort->status->value,
                'name' => $resort->name,
                'subtitle' => $resort->status->label().' · Brgy. '.$resort->barangay
                    .($resort->isPubliclyVisible() ? '' : ' · not public'),
                'lat' => (float) $resort->latitude,
                'lng' => (float) $resort->longitude,
                'image' => $resort->main_image_url,
                'url' => route('admin.resorts.show', $resort),
            ]);

        $destinations = Destination::query()
            ->with('category:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Destination $destination) => [
                'id' => 'destination-'.$destination->id,
                'type' => 'destination',
                'status' => $destination->status->value,
                'name' => $destination->name,
                'subtitle' => $destination->status->label().' · '.$destination->category?->name,
                'lat' => (float) $destination->latitude,
                'lng' => (float) $destination->longitude,
                'image' => $destination->main_image_url,
                'url' => route('admin.destinations.edit', $destination),
            ]);

        return view('admin.map', [
            'points' => $resorts->concat($destinations)->values(),
            'resorts' => $resorts,
            'destinations' => $destinations,
            'barangays' => $this->barangays->mappable(),
            // Named here rather than left to be noticed: a barangay with no
            // point is simply absent from the map, which looks like nothing
            // being wrong until somebody counts.
            'missingBarangays' => $this->barangays->missing(),
        ]);
    }
}
