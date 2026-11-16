<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingType;
use App\Enums\VisitorOrigin;
use App\Enums\WalkInSubmissionStatus;
use App\Exceptions\BookingException;
use App\Exceptions\WalkInException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\StoreWalkInRequest;
use App\Http\Requests\Owner\UpdateWalkInRequest;
use App\Models\Booking;
use App\Services\WalkInBookingService;
use App\Services\WalkInSubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in records for the signed-in owner's resort.
 *
 * Every query is limited with Booking::forOwner()->walkIns(), and every
 * action is checked against BookingPolicy first. A reference belonging to
 * another resort is simply not found — not refused, not found, which tells
 * a prying owner nothing about whether it exists.
 *
 * The services do the real work and own every rule; this reads the form,
 * asks them, and turns a refusal into something the owner can read.
 */
class WalkInController extends Controller
{
    public function __construct(
        private readonly WalkInBookingService $records,
        private readonly WalkInSubmissionService $office,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAnyWalkIn', Booking::class);

        $owner = $request->user();
        $resort = $owner->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first. Walk-in records belong to a resort.');
        }

        $status = WalkInSubmissionStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $records = Booking::query()
            ->forOwner($owner)
            ->walkIns()
            ->with(['room:id,name'])
            ->when($status, fn ($query) => $query->where('submission_status', $status->value))
            ->when($from, fn ($query) => $query->where('check_out_date', '>=', $from))
            ->when($to, fn ($query) => $query->where('check_in_date', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search): void {
                $match->where('booking_reference', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%");
            }))
            /*
             * Returned records first: those are the ones somebody has to do
             * something about, and a list that buried them under last
             * month's accepted stays would hide the only actionable thing
             * on the page.
             */
            ->orderByRaw("CASE WHEN submission_status = 'returned' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('check_in_date')
            ->paginate(config('visitdasol.per_page'))
            ->withQueryString();

        return view('owner.walk-ins.index', [
            'resort' => $resort,
            'records' => $records,
            'counts' => Booking::query()->forOwner($owner)->walkIns()
                ->selectRaw('submission_status, count(*) as total')
                ->groupBy('submission_status')
                ->pluck('total', 'submission_status'),
            'filters' => ['status' => $status, 'q' => $search, 'from' => $from, 'to' => $to],
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('createWalkIn', Booking::class);

        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create');
        }

        return view('owner.walk-ins.create', $this->formData($resort));
    }

    public function store(StoreWalkInRequest $request): RedirectResponse
    {
        $this->authorize('createWalkIn', Booking::class);

        $resort = $request->user()->resort()->firstOrFail();

        try {
            $record = $this->records->create(
                $resort,
                (int) $request->validated('room'),
                $request->validated(),
            );
        } catch (BookingException|WalkInException $e) {
            return back()->withInput()->withErrors(['room' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('owner.walk-ins.show', $record)
            ->with('success', "Saved as a draft. Reference {$record->booking_reference}. "
                .'The room is held from now; send it to the tourism office when you are ready.');
    }

    public function show(Booking $booking): View
    {
        $this->authorize('view', $booking);
        $this->mustBeWalkIn($booking);

        $booking->load(['room:id,name', 'resort:id,name', 'reviewedBy', 'submissions.actor']);

        return view('owner.walk-ins.show', [
            'record' => $booking,
            // Shown as a warning while it is still a draft, so the gap is
            // found before the office ever sees it.
            'missing' => $this->office->missingForSubmission($booking),
        ]);
    }

    public function edit(Booking $booking): View|RedirectResponse
    {
        $this->authorize('updateWalkIn', $booking);
        $this->mustBeWalkIn($booking);

        return view('owner.walk-ins.edit', [
            'record' => $booking,
        ] + $this->formData($booking->resort));
    }

    public function update(UpdateWalkInRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('updateWalkIn', $booking);
        $this->mustBeWalkIn($booking);

        try {
            $this->records->update($booking, $request->validated());
        } catch (BookingException|WalkInException $e) {
            return back()->withInput()->withErrors(['room' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('owner.walk-ins.show', $booking)
            ->with('success', 'The record has been corrected.');
    }

    /**
     * Send the record to the tourism office, or send a corrected one back.
     */
    public function submit(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('submitWalkIn', $booking);
        $this->mustBeWalkIn($booking);

        try {
            $record = $this->office->submit($booking, $request->user());
        } catch (WalkInException $e) {
            return back()->withErrors(['submission' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.walk-ins.show', $record)
            ->with('success', 'Sent to the Municipal Tourism Office. '
                .'You will be told if they need anything corrected.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * An online reservation is not a walk-in record and has no business on
     * these screens, even for the owner who may otherwise view it.
     */
    private function mustBeWalkIn(Booking $booking): void
    {
        abort_unless($booking->isWalkIn(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(mixed $resort): array
    {
        return [
            'resort' => $resort,
            'rooms' => $resort->rooms()->active()->orderBy('name')->get(),
            'origins' => collect(VisitorOrigin::cases())
                ->mapWithKeys(fn (VisitorOrigin $o): array => [$o->value => $o->label()])
                ->all(),
            'types' => collect(BookingType::cases())
                ->mapWithKeys(fn (BookingType $t): array => [$t->value => $t->label()])
                ->all(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
