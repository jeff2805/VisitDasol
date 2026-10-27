<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Only active resort owners reach this page (see the routes' middleware).
 * Everything shown is loaded through the signed-in user, never from the URL.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $owner = $request->user();
        $resort = $owner->resort()->withCount([
            'rooms',
            'rooms as active_rooms_count' => fn ($query) => $query->active(),
        ])->first();

        // Confirmed stays that have not finished yet: the same set behind the
        // list, the count, and the money, so the three can never disagree.
        $stillToCome = Booking::query()
            ->forOwner($owner)
            ->withStatus(BookingStatus::Confirmed)
            ->where('check_out_date', '>=', today()->toDateString());

        return view('owner.dashboard', [
            'owner' => $owner,
            'profile' => $owner->ownerProfile()->first(),
            'resort' => $resort,
            'waitingCount' => Booking::query()->forOwner($owner)->withStatus(BookingStatus::Pending)->count(),

            /*
             * What the confirmed stays still to come are worth, at the owner's
             * own listed rates.
             *
             * The dashboard carried no money figure at all, which left the one
             * role whose living depends on it having to open the statistics
             * page to learn whether anything was coming. One query, and only
             * over stays that are already confirmed — a request the owner has
             * not answered is not income.
             */
            'comingValue' => (float) (clone $stillToCome)->sum('total_amount'),
            'comingCount' => (clone $stillToCome)->count(),

            'upcoming' => (clone $stillToCome)
                ->with('room:id,name')
                ->orderBy('check_in_date')
                ->limit(5)
                ->get(),
        ]);
    }
}
