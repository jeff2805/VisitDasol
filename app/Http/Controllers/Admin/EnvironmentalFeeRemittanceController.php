<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RemittanceStatus;
use App\Exceptions\EnvironmentalFeeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnvironmentalFeeRemittanceReviewRequest;
use App\Models\EnvironmentalFeeRemittance;
use App\Services\EnvironmentalFeeRemittanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The office counting what the gates handed in.
 *
 * Reads money, never moves it. Nothing here touches a booking, a room
 * rate or a resort's income: the municipal fee and resort takings are
 * separate ledgers and this one knows nothing about the other.
 */
class EnvironmentalFeeRemittanceController extends Controller
{
    public function __construct(
        private readonly EnvironmentalFeeRemittanceService $remittances,
    ) {}

    /** Everything handed in, newest first, with what is still waiting. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', EnvironmentalFeeRemittance::class);

        $status = $request->query('status');

        $filtered = EnvironmentalFeeRemittance::query()
            ->when(
                in_array($status, RemittanceStatus::values(), true),
                fn ($query) => $query->where('status', $status)
            );

        return view('admin.environmental-fee.remittances.index', [
            'remittances' => $filtered
                ->with([
                    'checkpoint:id,name,barangay',
                    'collector:id,first_name,last_name',
                    'reviewedBy:id,first_name,last_name',
                ])
                ->latest('submitted_at')
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),

            'status' => $status,
            'statuses' => RemittanceStatus::options(),

            /*
             * The tiles. Counted here rather than in Blade so the page
             * runs three aggregates instead of walking a collection.
             */
            'waitingCount' => EnvironmentalFeeRemittance::query()->waiting()->count(),
            'waitingTotal' => (string) EnvironmentalFeeRemittance::query()
                ->waiting()->sum('declared_amount'),
            'disputedCount' => EnvironmentalFeeRemittance::query()->disputed()->count(),
        ]);
    }

    /** One handover, the receipts behind it, and the count form. */
    public function show(EnvironmentalFeeRemittance $remittance): View
    {
        $this->authorize('view', $remittance);

        $remittance->load([
            'checkpoint:id,name,barangay',
            'collector:id,first_name,last_name',
            'reviewedBy:id,first_name,last_name',
        ]);

        return view('admin.environmental-fee.remittances.show', [
            'remittance' => $remittance,
            'receipts' => $this->remittances->receiptsFor($remittance),

            /*
             * Worked out again, now. If a receipt has been voided since
             * the collector handed in, the figure they were quoted is
             * no longer what the receipts say — and the officer about
             * to count the cash needs to see both numbers, not one.
             */
            'live' => $this->remittances->expected(
                $remittance->checkpoint,
                $remittance->collector,
                $remittance->period_start,
                $remittance->period_end,
            ),

            // Whether THIS officer may sign it off. A super admin who
            // collected at the gate themselves may not.
            'mayReview' => request()->user()->can('review', $remittance),
        ]);
    }

    /** Count it, and record whether it agrees. */
    public function review(
        EnvironmentalFeeRemittanceReviewRequest $request,
        EnvironmentalFeeRemittance $remittance,
    ): RedirectResponse {
        $this->authorize('review', $remittance);

        try {
            $this->remittances->review(
                $remittance,
                $request->user(),
                (string) $request->validated('counted_amount'),
                $request->validated('review_note'),
            );
        } catch (EnvironmentalFeeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.environmental-fee.remittances.show', $remittance)
            ->with('success', 'Counted and recorded.');
    }

    /**
     * The handovers as a spreadsheet.
     *
     * Figures and the people responsible for them. No payer details:
     * nobody who paid a fee at a gate appears in a remittance, and
     * nothing here reaches into a booking.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', EnvironmentalFeeRemittance::class);

        $status = $request->query('status');

        $rows = EnvironmentalFeeRemittance::query()
            ->when(
                in_array($status, RemittanceStatus::values(), true),
                fn ($query) => $query->where('status', $status)
            )
            ->with([
                'checkpoint:id,name,barangay',
                'collector:id,first_name,last_name',
                'reviewedBy:id,first_name,last_name',
            ])
            ->latest('submitted_at')
            ->get();

        $name = 'environmental-fee-handovers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            // Excel reads a CSV as the system codepage without this, and
            // the ñ in Osmeña comes out broken.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Reference', 'Checkpoint', 'Barangay', 'Collector',
                'First day', 'Last day',
                'Receipts', 'Expected', 'Declared', 'Counted',
                'Difference', 'Status', 'Handed in', 'Counted by', 'Counted on',
                'Collector note', 'Office note',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->reference,
                    $row->checkpoint?->name,
                    $row->checkpoint?->barangay,
                    trim(($row->collector?->first_name ?? '').' '.($row->collector?->last_name ?? '')),
                    $row->period_start->toDateString(),
                    $row->period_end->toDateString(),
                    $row->expected_receipts,
                    $row->expected_amount,
                    $row->declared_amount,
                    $row->counted_amount ?? '',
                    $row->variance() ?? '',
                    $row->status->label(),
                    $row->submitted_at?->format('Y-m-d H:i'),
                    trim(($row->reviewedBy?->first_name ?? '').' '.($row->reviewedBy?->last_name ?? '')),
                    $row->reviewed_at?->format('Y-m-d H:i'),
                    $row->collector_note,
                    $row->review_note,
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
