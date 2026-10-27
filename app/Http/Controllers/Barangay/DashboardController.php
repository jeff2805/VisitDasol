<?php

namespace App\Http\Controllers\Barangay;

use App\Enums\DestinationStatus;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * What a barangay official sees when they sign in: how their barangay's
 * destinations are doing, and anything the office has sent back.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $official = $request->user();

        // One grouped query rather than four counts.
        $counts = Destination::query()
            ->submittedBy($official)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('barangay.dashboard', [
            'official' => $official,
            'counts' => $counts,
            'published' => (int) $counts->get(DestinationStatus::Active->value, 0),
            'waiting' => (int) $counts->get(DestinationStatus::Pending->value, 0),
            'sentBack' => (int) $counts->get(DestinationStatus::Rejected->value, 0),
            // The ones needing the official's attention come first, because
            // a spot the office sent back is the only thing here that is
            // waiting on them rather than on somebody else.
            'needsAttention' => Destination::query()
                ->submittedBy($official)
                ->withStatus(DestinationStatus::Rejected)
                ->latest('reviewed_at')
                ->limit(5)
                ->get(),
            'recent' => Destination::query()
                ->submittedBy($official)
                ->with('category')
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }
}
