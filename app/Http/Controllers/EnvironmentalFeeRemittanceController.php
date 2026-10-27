<?php

namespace App\Http\Controllers;

use App\Exceptions\EnvironmentalFeeException;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeRemittance;
use App\Http\Requests\EnvironmentalFeeRemittanceRequest;
use App\Services\EnvironmentalFeeRemittanceService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A collector handing a period's takings over to the office.
 *
 * Beside EnvironmentalFeeCollectionController and outside the admin
 * prefix for the same reason: the people who work the gates are not
 * necessarily the tourism office. Authority comes from an assignment
 * row, checked by the policy here and again inside the service.
 */
class EnvironmentalFeeRemittanceController extends Controller
{
    public function __construct(
        private readonly EnvironmentalFeeRemittanceService $remittances,
    ) {}

    /** What this person owes, and what they have already handed in. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', EnvironmentalFeeRemittance::class);

        $user = $request->user();

        $checkpoints = $user->isSuperAdmin()
            ? EnvironmentalFeeCheckpoint::query()->active()->orderBy('name')->get()
            : EnvironmentalFeeCheckpoint::query()->collectedBy($user)->orderBy('name')->get();

        return view('environmental-fee.remittances.index', [
            'checkpoints' => $checkpoints,

            /*
             * Their own handovers only. A collector has no business
             * reading what another gate turned in, and the office has
             * its own screen for the whole municipality.
             */
            'remittances' => EnvironmentalFeeRemittance::query()
                ->when(
                    ! $user->isSuperAdmin(),
                    fn ($query) => $query->where('collector_id', $user->id)
                )
                ->with([
                    'checkpoint:id,name,barangay',
                    'collector:id,first_name,last_name',
                    'reviewedBy:id,first_name,last_name',
                ])
                ->latest('submitted_at')
                ->paginate(config('visitdasol.per_page')),

            // Sensible dates for the form: yesterday through today, the
            // commonest shift to be handing in.
            'defaultStart' => CarbonImmutable::yesterday()->toDateString(),
            'defaultEnd' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /**
     * What the receipts say is owed for a gate and a period.
     *
     * Asked for before submitting, so a collector counts against the
     * same figure the office will. Worked out on the server; the form
     * never supplies it.
     */
    public function expected(Request $request): View
    {
        $this->authorize('create', EnvironmentalFeeRemittance::class);

        $validated = $request->validate([
            'checkpoint_id' => ['required', 'integer', 'exists:environmental_fee_checkpoints,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $checkpoint = EnvironmentalFeeCheckpoint::findOrFail($validated['checkpoint_id']);

        // Which gate, and whether this person may work it.
        $this->authorize('collectAt', $checkpoint);

        $expected = $this->remittances->expected(
            $checkpoint,
            $request->user(),
            CarbonImmutable::parse($validated['period_start']),
            CarbonImmutable::parse($validated['period_end']),
        );

        return view('environmental-fee.remittances.expected', [
            'checkpoint' => $checkpoint,
            'from' => $validated['period_start'],
            'to' => $validated['period_end'],
            'expected' => $expected,
        ]);
    }

    /** Hand a period over. */
    public function store(EnvironmentalFeeRemittanceRequest $request): RedirectResponse
    {
        $this->authorize('create', EnvironmentalFeeRemittance::class);

        $checkpoint = EnvironmentalFeeCheckpoint::findOrFail(
            $request->validated('checkpoint_id')
        );

        $this->authorize('collectAt', $checkpoint);

        try {
            $remittance = $this->remittances->submit(
                $request->user(),
                $checkpoint,
                CarbonImmutable::parse($request->validated('period_start')),
                CarbonImmutable::parse($request->validated('period_end')),
                (string) $request->validated('declared_amount'),
                $request->validated('collector_note'),
            );
        } catch (EnvironmentalFeeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('environmental-fee.remittances.show', $remittance)
            ->with('success', 'Handed in. Keep the slip until the office has counted it.');
    }

    /** One handover, with the receipts behind it. */
    public function show(EnvironmentalFeeRemittance $remittance): View
    {
        $this->authorize('view', $remittance);

        $remittance->load([
            'checkpoint:id,name,barangay',
            'collector:id,first_name,last_name',
            'reviewedBy:id,first_name,last_name',
        ]);

        return view('environmental-fee.remittances.show', [
            'remittance' => $remittance,
            'receipts' => $this->remittances->receiptsFor($remittance),

            /*
             * Recalculated now, not read off the row. A receipt voided
             * since submission changes what is owed, and a slip that
             * went on quoting the old figure would be the one thing
             * nobody could reconcile.
             */
            'live' => $this->remittances->expected(
                $remittance->checkpoint,
                $remittance->collector,
                $remittance->period_start,
                $remittance->period_end,
            ),
        ]);
    }
}
