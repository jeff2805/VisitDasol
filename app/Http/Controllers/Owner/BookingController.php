<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\CancelBookingRequest;
use App\Http\Requests\Owner\RejectBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingStatusManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reservations for the signed-in owner's resort.
 *
 * Every query is limited with Booking::forOwner(), and every action is
 * checked against BookingPolicy, which compares the reservation's resort
 * with the resorts this owner has. A reference from another resort simply
 * is not found.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingStatusManager $status) {}

    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Booking::class);

        $owner = $request->user();
        $resort = $owner->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first. Reservations appear here once guests can book.');
        }

        $status = BookingStatus::tryFrom((string) $request->query('status'));
        $room = $resort->rooms()->whereKey($request->query('room'))->first();
        $search = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $bookings = Booking::query()
            ->forOwner($owner)
            ->with(['room:id,name', 'tourist:id,first_name,last_name,email'])
            ->when($status, fn ($query) => $query->withStatus($status))
            ->when($room, fn ($query) => $query->where('room_id', $room->id))
            ->when($from, fn ($query) => $query->where('check_out_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('check_in_date', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search) {
                $match->where('booking_reference', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%");
            }))
            ->orderByRaw("FIELD(status, 'pending') DESC")
            ->orderBy('check_in_date')
            ->paginate(config('visitdasol.per_page'))
            ->withQueryString();

        return view('owner.bookings.index', [
            'resort' => $resort,
            'bookings' => $bookings,
            'rooms' => $resort->rooms()->orderBy('name')->pluck('name', 'id'),
            'counts' => Booking::query()->forOwner($owner)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'filters' => [
                'status' => $status,
                'room' => $room,
                'q' => $search,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function show(Booking $booking): View
    {
        $this->authorize('view', $booking);

        $booking->load(['room.images', 'tourist', 'resort', 'cancelledBy', 'payments']);

        $payments = app(\App\Services\Payments\PaymentService::class);

        return view('owner.bookings.show', [
            'booking' => $booking,

            // The terms this guest agreed to, from the booking's own
            // snapshot — not the resort's current policy, which may
            // have changed since.
            'terms' => app(\App\Services\Payments\PaymentPolicyService::class)->stored($booking),

            'dueNow' => $payments->dueNow($booking),
            'paid' => (float) $booking->total_amount - $payments->outstanding($booking),
        ]);
    }

    public function confirm(Booking $booking): RedirectResponse
    {
        $this->authorize('confirm', $booking);

        return $this->run(fn () => $this->status->confirm($booking),
            "{$booking->booking_reference} is confirmed. The guest can see it in their reservations.");
    }

    public function reject(RejectBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('reject', $booking);

        return $this->run(fn () => $this->status->reject($booking, $request->validated('owner_remarks')),
            "{$booking->booking_reference} was declined and the dates are free again.");
    }

    public function complete(Booking $booking): RedirectResponse
    {
        $this->authorize('complete', $booking);

        return $this->run(fn () => $this->status->complete($booking),
            "{$booking->booking_reference} is marked completed.");
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('cancelAsOwner', $booking);

        return $this->run(fn () => $this->status->cancel($booking, $request->user(), $request->validated('cancellation_reason')),
            "{$booking->booking_reference} was cancelled and the guest can see your reason.");
    }

    /**
     * Runs a status change and turns a refusal into a message the owner
     * can act on, rather than an error page.
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
