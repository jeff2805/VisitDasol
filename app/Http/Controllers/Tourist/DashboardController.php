<?php

namespace App\Http\Controllers\Tourist;

use App\Enums\BookingStatus;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Resort;
use App\Services\Payments\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The tourist's home after signing in.
 *
 * It answers the three things a guest opens the site for, in order:
 * what is happening with my stay, what am I waiting on, and where could
 * I go next. Every query is scoped to the signed-in user's own bookings.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $today = today()->toDateString();

        // The stay to show at the top: the nearest one still to come,
        // including a stay that has already started but not yet ended.
        $nextStay = $user->bookings()
            ->withStatus(BookingStatus::Confirmed)
            ->where('check_out_date', '>=', $today)
            ->with(['resort', 'room'])
            ->orderBy('check_in_date')
            ->first();

        /*
         * What is still unpaid on that stay, when it can be paid here.
         *
         * The dashboard used to show the total and nothing else, so a guest
         * whose reservation was confirmed and unpaid had no way of knowing
         * until they opened the reservation itself.
         *
         * Exactly the test the reservation page makes before it offers a pay
         * button — a configured gateway, and something left to pay on a
         * confirmed stay — and deliberately nothing more. Checking the guest's
         * stated preference as well would make this stricter than that page,
         * which offers to take the money whatever they chose on the form, and
         * then the two would disagree about the same reservation.
         *
         * Null means there is nothing to say.
         */
        $dueOnNextStay = $nextStay
            && PaymentProvider::available() !== []
            && $this->payments->isPayable($nextStay)
                ? $this->payments->outstanding($nextStay)
                : null;

        return view('tourist.dashboard', [
            'user' => $user,
            'nextStay' => $nextStay,
            'dueOnNextStay' => $dueOnNextStay,

            'stats' => [
                'upcoming' => $user->bookings()
                    ->withStatus(BookingStatus::Confirmed)
                    ->where('check_out_date', '>=', $today)
                    ->count(),
                'pending' => $user->bookings()->withStatus(BookingStatus::Pending)->count(),
                'completed' => $user->bookings()->withStatus(BookingStatus::Completed)->count(),
            ],

            'recentBookings' => $user->bookings()
                ->with('resort')
                ->latest()
                ->limit(4)
                ->get(),

            // Same shape the home page uses, so the cards render identically.
            'resorts' => Resort::publiclyVisible()
                ->with('amenities')
                ->withMin(['rooms as starting_price' => fn ($query) => $query->active()], 'price_per_night')
                ->orderByDesc('is_featured')
                ->orderBy('name')
                ->limit(3)
                ->get(),
        ]);
    }
}
