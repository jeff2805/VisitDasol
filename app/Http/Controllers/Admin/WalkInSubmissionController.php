<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WalkInSubmissionStatus;
use App\Exceptions\WalkInException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReturnWalkInRequest;
use App\Models\Booking;
use App\Models\Resort;
use App\Services\WalkInSubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Walk-in records the resorts have sent to the Municipal Tourism Office.
 *
 * THE OFFICE SEES VISITORS, NEVER MONEY.
 *
 * Not a view-level nicety: no money column is loaded on these screens at
 * all. The select lists are written out field by field so that adding a
 * column to the bookings table can never quietly put a room rate in front
 * of a reviewer, and BookingPolicy has no walk-in method that would let the
 * office change a figure — only accept it, or send it back with a reason.
 *
 * Drafts are invisible here. A record the resort has not sent is not the
 * office's to see, let alone decide on.
 */
class WalkInSubmissionController extends Controller
{
    /** Everything these screens may read. No rate, total, or payment. */
    private const VISIBLE = [
        'id', 'booking_reference', 'resort_id', 'room_id', 'booking_source',
        'submission_status', 'submitted_at', 'reviewed_at', 'reviewed_by',
        'return_remarks', 'accepted_at',
        'check_in_date', 'check_out_date', 'check_in_time', 'check_out_time',
        'booking_type', 'nights', 'guests', 'adults', 'children', 'pwd_guests',
        // How many beds the party gets. Not money, and <x-guest-party> says it.
        'rooms_booked', 'beds', 'guests_male', 'guests_female', 'origin',
        'contact_name', 'contact_phone', 'guest_address', 'special_requests',
        'status', 'created_at',
    ];

    public function __construct(private readonly WalkInSubmissionService $office) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAnyWalkIn', Booking::class);

        $status = WalkInSubmissionStatus::tryFrom((string) $request->query('status'));
        $resort = Resort::query()->whereKey($request->query('resort'))->first();
        $search = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $submittedFrom = $this->date($request->query('submitted_from'));
        $submittedTo = $this->date($request->query('submitted_to'));

        $records = Booking::query()
            ->walkIns()
            // A draft belongs to the resort, not to the office.
            ->whereNotNull('submitted_at')
            ->whereIn('submission_status', [
                WalkInSubmissionStatus::PendingReview->value,
                WalkInSubmissionStatus::Resubmitted->value,
                WalkInSubmissionStatus::Returned->value,
                WalkInSubmissionStatus::Accepted->value,
            ])
            ->select(self::VISIBLE)
            ->with(['resort:id,name,barangay', 'room:id,name'])
            ->when($status, fn ($query) => $query->where('submission_status', $status->value))
            ->when($resort, fn ($query) => $query->where('resort_id', $resort->id))
            ->when($from, fn ($query) => $query->where('check_in_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('check_in_date', '<=', $to))
            ->when($submittedFrom, fn ($query) => $query->whereDate('submitted_at', '>=', $submittedFrom))
            ->when($submittedTo, fn ($query) => $query->whereDate('submitted_at', '<=', $submittedTo))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search): void {
                $match->where('booking_reference', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhereHas('resort', fn ($r) => $r->where('name', 'like', "%{$search}%"));
            }))
            // Waiting first, oldest wait at the top: a queue, not a list.
            ->orderByRaw("CASE WHEN submission_status IN ('pending_review','resubmitted') THEN 1 ELSE 0 END DESC")
            ->orderBy('submitted_at')
            ->paginate(config('visitdasol.per_page'))
            ->withQueryString();

        return view('admin.walk-ins.index', [
            'records' => $records,
            'resorts' => Resort::query()->orderBy('name')->pluck('name', 'id'),
            'counts' => Booking::query()->walkIns()->whereNotNull('submitted_at')
                ->selectRaw('submission_status, count(*) as total')
                ->groupBy('submission_status')
                ->pluck('total', 'submission_status'),
            'filters' => [
                'status' => $status, 'resort' => $resort, 'q' => $search,
                'from' => $from, 'to' => $to,
                'submitted_from' => $submittedFrom, 'submitted_to' => $submittedTo,
            ],
        ]);
    }

    public function show(Booking $booking): View
    {
        $this->authorize('viewAnyWalkIn', Booking::class);
        $this->mustBeSubmitted($booking);

        /*
         * Re-read through the same narrow select the list uses, so the
         * detail page cannot show a money column the list was careful to
         * leave behind.
         */
        $record = Booking::query()
            ->whereKey($booking->id)
            ->select(self::VISIBLE)
            ->with(['resort:id,name,barangay', 'room:id,name', 'reviewedBy', 'submissions.actor'])
            ->firstOrFail();

        return view('admin.walk-ins.show', ['record' => $record]);
    }

    public function accept(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('reviewWalkIn', $booking);

        try {
            $this->office->accept($booking, $request->user());
        } catch (WalkInException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.walk-ins.show', $booking)
            ->with('success', "Accepted {$booking->booking_reference}. "
                .'It now counts towards the tourism report.');
    }

    public function returnForCorrection(ReturnWalkInRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('reviewWalkIn', $booking);

        try {
            $this->office->returnForCorrection(
                $booking,
                $request->user(),
                (string) $request->validated('return_remarks'),
            );
        } catch (WalkInException $e) {
            return back()->withErrors(['return_remarks' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.walk-ins.index')
            ->with('success', "Returned {$booking->booking_reference} to the resort for correction.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * A walk-in the resort has actually sent. A draft, or an online
     * reservation, is not found rather than refused.
     */
    private function mustBeSubmitted(Booking $booking): void
    {
        abort_unless($booking->isWalkIn() && $booking->submitted_at !== null, 404);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
