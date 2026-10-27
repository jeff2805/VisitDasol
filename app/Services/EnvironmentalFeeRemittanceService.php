<?php

namespace App\Services;

use App\Enums\CollectionStatus;
use App\Enums\RemittanceStatus;
use App\Exceptions\EnvironmentalFeeException;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeeRemittance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handing collected fee money over to the office, and counting it.
 *
 * THE EXPECTED FIGURE IS NEVER TAKEN FROM THE FORM. It is summed here
 * from the collections themselves, every time, because the whole value
 * of a reconciliation is that one side of it was not supplied by the
 * person being reconciled.
 *
 * Nothing in this class moves money or talks to a bank. It records that
 * a collector declared a figure and that a named officer counted
 * another, and it keeps both so the difference can be chased.
 */
class EnvironmentalFeeRemittanceService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * What the receipts say a collector owes for a period at a gate.
     *
     * Voided and corrected receipts are excluded by counted(), the same
     * scope the reports use, so a remittance and a report can never
     * disagree about what was taken.
     *
     * GCash receipts awaiting verification are excluded too: money that
     * may or may not have arrived in a wallet is not money a collector
     * can hand over in an envelope.
     *
     * @return array{amount: string, receipts: int, quantity: int}
     */
    public function expected(
        EnvironmentalFeeCheckpoint $checkpoint,
        User $collector,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $query = EnvironmentalFeeCollection::query()
            ->counted()
            ->where('checkpoint_id', $checkpoint->id)
            ->where('collector_id', $collector->id)
            ->where('payment_method', 'cash')
            ->whereBetween('collected_at', [
                $from->startOfDay(),
                $to->endOfDay(),
            ]);

        return [
            'amount' => $this->pesos($this->centavoSum($query->clone())),
            'receipts' => $query->clone()->count(),
            'quantity' => (int) $query->clone()->sum('quantity'),
        ];
    }

    /**
     * The receipts behind a remittance, for the slip and the review.
     *
     * Looked up by period rather than stored as a list: a receipt
     * voided after submission must drop out of the reckoning, and a
     * frozen list would go on claiming it.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EnvironmentalFeeCollection>
     */
    public function receiptsFor(EnvironmentalFeeRemittance $remittance)
    {
        return EnvironmentalFeeCollection::query()
            ->counted()
            ->where('checkpoint_id', $remittance->checkpoint_id)
            ->where('collector_id', $remittance->collector_id)
            ->where('payment_method', 'cash')
            ->whereBetween('collected_at', [
                $remittance->period_start->startOfDay(),
                $remittance->period_end->endOfDay(),
            ])
            ->orderBy('collected_at')
            ->get();
    }

    /**
     * A collector hands in a period's takings.
     *
     * @throws EnvironmentalFeeException
     */
    public function submit(
        User $collector,
        EnvironmentalFeeCheckpoint $checkpoint,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $declared,
        ?string $note = null,
    ): EnvironmentalFeeRemittance {
        if ($to->lt($from)) {
            throw new EnvironmentalFeeException('The last day cannot be before the first.');
        }

        if ($from->gt(CarbonImmutable::today())) {
            throw new EnvironmentalFeeException('That period has not happened yet.');
        }

        return DB::transaction(function () use ($collector, $checkpoint, $from, $to, $declared, $note) {
            /*
             * Re-read inside the transaction and under a lock. Two taps
             * on a slow connection are the ordinary way one night's
             * takings become two liabilities, and the unique index is
             * the backstop if this check is ever bypassed.
             */
            $existing = EnvironmentalFeeRemittance::query()
                ->where('checkpoint_id', $checkpoint->id)
                ->where('collector_id', $collector->id)
                ->where('period_start', $from->toDateString())
                ->where('period_end', $to->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new EnvironmentalFeeException(
                    'You have already handed in '.$from->format('M j').' to '
                    .$to->format('M j, Y').' for this checkpoint ('.$existing->reference.').'
                );
            }

            $expected = $this->expected($checkpoint, $collector, $from, $to);

            if ($expected['receipts'] < 1) {
                throw new EnvironmentalFeeException(
                    'There are no cash receipts from you at this checkpoint for those dates, '
                    .'so there is nothing to hand in.'
                );
            }

            $remittance = EnvironmentalFeeRemittance::create([
                'reference' => $this->reference(CarbonImmutable::now()),
                'checkpoint_id' => $checkpoint->id,
                'collector_id' => $collector->id,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),

                // Frozen at submission so a reprinted slip still says
                // what was claimed at the time. The live figure is
                // recalculated on review and any drift is shown.
                'expected_amount' => $expected['amount'],
                'expected_receipts' => $expected['receipts'],

                'declared_amount' => $declared,
                'submitted_at' => CarbonImmutable::now(),
                'collector_note' => $note,
            ]);

            $this->logger->log(
                'environmental_fee.remittance_submitted',
                $remittance,
                $collector->first_name.' '.$collector->last_name.' handed in '
                .$remittance->declared_amount.' for '.$checkpoint->name,
                [
                    // Figures only. Never a payer, never a plate number.
                    'reference' => $remittance->reference,
                    'expected' => $remittance->expected_amount,
                    'declared' => $remittance->declared_amount,
                    'receipts' => $remittance->expected_receipts,
                    'period' => $from->toDateString().'..'.$to->toDateString(),
                ],
                $collector,
            );

            return $remittance;
        });
    }

    /**
     * The office counts what was handed in and says whether it agrees.
     *
     * The status is DERIVED from the figures, not chosen by the
     * reviewer. An officer cannot mark a short envelope "agreed" by
     * picking the wrong option from a menu.
     *
     * @throws EnvironmentalFeeException
     */
    public function review(
        EnvironmentalFeeRemittance $remittance,
        User $officer,
        string $counted,
        ?string $note = null,
    ): EnvironmentalFeeRemittance {
        if ($remittance->status->isReviewed()) {
            throw new EnvironmentalFeeException(
                'This handover was already counted on '
                .$remittance->reviewed_at?->format('M j, Y g:i A').'.'
            );
        }

        return DB::transaction(function () use ($remittance, $officer, $counted, $note) {
            $locked = EnvironmentalFeeRemittance::query()
                ->whereKey($remittance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isReviewed()) {
                throw new EnvironmentalFeeException('This handover was already counted.');
            }

            $countedCentavos = $this->centavos($counted);
            $expectedCentavos = $this->centavos($locked->expected_amount);

            /*
             * Agreed only when the count matches the receipts to the
             * centavo. Everything else is a discrepancy, including an
             * overage: money nobody wrote a receipt for is as much a
             * problem as money missing.
             */
            $status = $countedCentavos === $expectedCentavos
                ? RemittanceStatus::Reconciled
                : RemittanceStatus::Disputed;

            if ($status === RemittanceStatus::Disputed && blank($note)) {
                throw new EnvironmentalFeeException(
                    'The count does not match the receipts, so a note explaining '
                    .'the difference is required.'
                );
            }

            $locked->forceFill([
                'counted_amount' => $this->pesos($countedCentavos),
                'status' => $status,
                'reviewed_by' => $officer->id,
                'reviewed_at' => CarbonImmutable::now(),
                'review_note' => $note,
            ])->save();

            $this->logger->logStatusChange(
                'environmental_fee.remittance_reviewed',
                $locked,
                'Counted '.$locked->reference.': '
                .($status === RemittanceStatus::Reconciled
                    ? 'agrees with the receipts'
                    : 'does NOT agree with the receipts'),
                RemittanceStatus::Submitted->value,
                $status->value,
                [
                    'expected' => $locked->expected_amount,
                    'declared' => $locked->declared_amount,
                    'counted' => $locked->counted_amount,
                    'variance' => $locked->variance(),
                ],
                $officer,
            );

            return $locked->refresh();
        });
    }

    /* Money, in whole centavos ------------------------------------------- */

    private function centavos(string|float|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Summed in the database, then rounded once. Summing floats in PHP
     * across a few hundred receipts is how a peso goes missing.
     */
    private function centavoSum($query): int
    {
        return (int) round((float) $query->sum('amount') * 100);
    }

    /** Short, quotable, and not guessable from the one before it. */
    private function reference(CarbonImmutable $at): string
    {
        do {
            $reference = 'VDR-'.$at->format('ymd').'-'.Str::upper(Str::random(6));
        } while (
            EnvironmentalFeeRemittance::query()->where('reference', $reference)->exists()
        );

        return $reference;
    }

    /**
     * Statuses a remittance never counts against, kept here so a
     * future caller cannot reach for the wrong set.
     *
     * @return list<string>
     */
    public static function uncountedStatuses(): array
    {
        return [CollectionStatus::Voided->value, CollectionStatus::Corrected->value];
    }
}
