<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CollectionStatus;
use App\Http\Controllers\Controller;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeePolicy;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the municipality has recorded collecting.
 *
 * WHAT THESE TOTALS ARE: the sum of receipts collectors wrote down.
 *
 * WHAT THEY ARE NOT: money the municipality is holding. The portal has
 * no payment gateway for this fee and cannot see a bank account, so it
 * cannot say that anything was banked. Every screen here says so, and
 * so does the export, because a total labelled "collected" is read as
 * "in the account" by whoever gets the spreadsheet.
 *
 * Handovers are a separate record and a separate screen: what a gate
 * turned in and what the office counted is tracked by
 * EnvironmentalFeeRemittanceController, and still is not a deposit.
 *
 * Nothing here can see resort income. The collections table has no link
 * to a booking or a resort, so there is nothing to join to.
 */
class EnvironmentalFeeReportController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        [$from, $to] = $this->range($request);

        $counted = fn () => EnvironmentalFeeCollection::query()
            ->counted()
            ->collectedBetween($from, $to);

        return view('admin.environmental-fee.reports', [
            'from' => $from,
            'to' => $to,

            // Recorded, and separately what was voided. Keeping them
            // apart is the difference between a total and a story.
            'total' => (string) $counted()->sum('amount'),
            'count' => $counted()->count(),
            'quantity' => (int) $counted()->sum('quantity'),

            'voidedTotal' => (string) EnvironmentalFeeCollection::query()
                ->where('status', CollectionStatus::Voided->value)
                ->collectedBetween($from, $to)
                ->sum('amount'),

            'voidedCount' => EnvironmentalFeeCollection::query()
                ->where('status', CollectionStatus::Voided->value)
                ->collectedBetween($from, $to)
                ->count(),

            /*
             * GCash transfers nobody has checked. Shown on its own and
             * NEVER folded into the total above: this portal cannot see
             * a wallet, so an unchecked transfer is a claim that money
             * was sent, not a record that it came.
             */
            'awaitingTotal' => (string) EnvironmentalFeeCollection::query()
                ->awaitingVerification()
                ->collectedBetween($from, $to)
                ->sum('amount'),

            'awaitingCount' => EnvironmentalFeeCollection::query()
                ->awaitingVerification()
                ->collectedBetween($from, $to)
                ->count(),

            // How the confirmed money came in, which is what a treasurer
            // reconciles against: one figure for the cash box, one for
            // the GCash account.
            'cashTotal' => (string) $counted()
                ->where('payment_method', 'cash')
                ->sum('amount'),

            'gcashTotal' => (string) $counted()
                ->where('payment_method', 'gcash')
                ->sum('amount'),

            'byCheckpoint' => $counted()
                ->selectRaw('checkpoint_id, count(*) as receipts, sum(amount) as total')
                ->groupBy('checkpoint_id')
                ->with('checkpoint:id,name,barangay')
                ->get(),

            'byCollector' => $counted()
                ->selectRaw('collector_id, count(*) as receipts, sum(amount) as total')
                ->groupBy('collector_id')
                ->with('collector:id,first_name,last_name')
                ->get(),

            /*
             * Day by day across the chosen range. DATE() rather than a
             * PHP grouping so a busy month is one row per day out of
             * the database instead of every receipt in memory.
             */
            'byDay' => $counted()
                ->selectRaw('DATE(collected_at) as day, count(*) as receipts, sum(quantity) as people, sum(amount) as total')
                ->groupBy('day')
                ->orderBy('day')
                ->get(),

            /*
             * Month by month. Worth having beside the daily figures
             * because a treasurer reports monthly and should not be
             * adding thirty rows up by hand.
             */
            'byMonth' => $counted()
                ->selectRaw("DATE_FORMAT(collected_at, '%Y-%m') as month, count(*) as receipts, sum(quantity) as people, sum(amount) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get(),

            /*
             * By barangay, through the checkpoint that took the money.
             * A join rather than a relation: barangay lives on the
             * checkpoint, and grouping in the database keeps this to
             * one row per barangay however many gates it has.
             */
            'byBarangay' => $counted()
                ->join(
                    'environmental_fee_checkpoints',
                    'environmental_fee_checkpoints.id',
                    '=',
                    'environmental_fee_collections.checkpoint_id'
                )
                ->selectRaw('environmental_fee_checkpoints.barangay as barangay, count(*) as receipts, sum(environmental_fee_collections.quantity) as people, sum(environmental_fee_collections.amount) as total')
                ->groupBy('barangay')
                ->orderBy('barangay')
                ->get(),

            /*
             * Handovers touching this range, so the office can see at a
             * glance whether what was collected has been turned in.
             * Counts only — the figures live on their own screen.
             */
            'remittanceWaiting' => \App\Models\EnvironmentalFeeRemittance::query()
                ->waiting()->count(),
            'remittanceDisputed' => \App\Models\EnvironmentalFeeRemittance::query()
                ->disputed()->count(),

            'voided' => EnvironmentalFeeCollection::query()
                ->whereIn('status', [
                    CollectionStatus::Voided->value,
                    CollectionStatus::Corrected->value,
                ])
                ->collectedBetween($from, $to)
                ->with(['checkpoint:id,name', 'voidedBy:id,first_name,last_name'])
                ->latest('collected_at')
                ->limit(50)
                ->get(),

            'checkpoints' => EnvironmentalFeeCheckpoint::query()->orderBy('name')->get(),
        ]);
    }

    /** Look a receipt up by its number or the system's reference. */
    public function lookup(Request $request): View
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        $term = trim((string) $request->query('q', ''));

        return view('admin.environmental-fee.lookup', [
            'term' => $term,
            'results' => $term === '' ? collect() : EnvironmentalFeeCollection::query()
                ->where('receipt_number', $term)
                ->orWhere('reference', $term)
                ->with(['checkpoint:id,name', 'collector:id,first_name,last_name'])
                ->get(),
        ]);
    }

    /**
     * The same figures as a spreadsheet, using the project's existing
     * CSV conventions: a UTF-8 mark so Excel reads it, and a filename
     * carrying the range.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        [$from, $to] = $this->range($request);

        // Exporting is an event worth recording: it is the moment
        // municipal collection figures leave the system.
        $this->logger->log(
            'environmental_fee.report_exported',
            null,
            "Exported environmental fee collections, {$from} to {$to}",
            ['from' => $from, 'to' => $to],
        );

        $name = 'dasol-environmental-fee-'.$from.'-to-'.$to.'.csv';

        return response()->streamDownload(function () use ($from, $to): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Help Microsoft Excel recognize UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Reference', 'Receipt', 'Collected at', 'Checkpoint', 'Barangay',
                'Collector', 'Rate', 'Scope', 'Quantity', 'Amount',
                'Paid by', 'GCash reference', 'Money', 'Checked by',
                'Status', 'Void reason',
            ]);

            EnvironmentalFeeCollection::query()
                ->collectedBetween($from, $to)
                ->with([
                    'checkpoint:id,name,barangay',
                    'collector:id,first_name,last_name',
                    'verifiedBy:id,first_name,last_name',
                ])
                ->orderBy('collected_at')
                ->chunk(500, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->reference,
                            $row->receipt_number,
                            $row->collected_at?->format('Y-m-d H:i'),
                            $row->checkpoint?->name,
                            $row->checkpoint?->barangay,
                            $row->collector?->full_name,
                            $row->rate,
                            $row->scope?->value,
                            $row->quantity,
                            $row->amount,
                            $row->payment_method,
                            $row->gcash_reference,
                            // Whether the money is actually accounted for,
                            // which the Amount column alone does not say.
                            $row->payment_status->value,
                            $row->verifiedBy?->full_name,
                            $row->status->value,
                            $row->void_reason,
                        ]);
                    }
                });

            /*
             * Said in the file itself. A spreadsheet outlives the page it
             * came from, and a column headed "Amount" is read as money in
             * hand by whoever opens it next.
             */
            fputcsv($handle, []);
            fputcsv($handle, [
                'These are receipts recorded at checkpoints. They are not a record of '
                .'money remitted to or deposited with the municipality. Voided rows are '
                .'included above and are marked in the Status column.',
            ]);
            fputcsv($handle, [
                'The Money column says whether the payment is accounted for. '
                .'"awaiting_verification" means a GCash transfer nobody has found in the '
                .'app yet — it is NOT money the municipality holds, and it is excluded '
                .'from every total in the on-screen report.',
            ]);

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The dates asked about, defaulting to this month.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        $parse = function (mixed $value) use ($today): ?CarbonImmutable {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }

            try {
                return CarbonImmutable::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($request->query('from')) ?? $today->startOfMonth();
        $to = $parse($request->query('to')) ?? $today;

        // A backwards range is a typo, not a question.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
