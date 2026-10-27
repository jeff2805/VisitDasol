<?php

namespace App\Services;

use App\Enums\CollectionStatus;
use App\Enums\FeePaymentStatus;
use App\Exceptions\EnvironmentalFeeException;
use App\Models\EnvironmentalFeeAccount;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeePolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The municipality's environmental fee: what may be collected, and the
 * recording of what was.
 *
 * The ONLY place a collection row is written. Every other part of the
 * module asks this class, so the six conditions below are checked once
 * and cannot be forgotten by a new screen.
 *
 * SEPARATE FROM RESORT MONEY. This class does not know what a booking
 * is. It never reads a room rate, never touches a payment, never adds
 * anything to what a guest owes a resort. That is not an oversight to
 * be filled in later; it is the module's purpose.
 *
 * IT DOES NOT PROCESS PAYMENTS. A row recorded here says a collector
 * wrote a receipt at a gate. The portal has no gateway for municipal
 * fees, so nothing here may be read as money cleared, remitted or
 * banked.
 */
class EnvironmentalFeeService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * The policy in force today, or null when nothing may be collected.
     *
     * Null is the ordinary answer: the module ships switched off with no
     * rate, and stays that way until an officer configures one.
     */
    public function activePolicy(?CarbonImmutable $on = null): ?EnvironmentalFeePolicy
    {
        $on ??= CarbonImmutable::today(config('app.timezone'));

        return EnvironmentalFeePolicy::query()->inForceOn($on)->latest('id')->first();
    }

    /** Whether anything at all may be collected today. */
    public function isCollectable(?CarbonImmutable $on = null): bool
    {
        $on ??= CarbonImmutable::today(config('app.timezone'));

        return $this->activePolicy($on)?->isCollectableOn($on) === true;
    }

    /**
     * Why nothing may be collected, for the screen that has to explain
     * itself to an officer. Null when collection is possible.
     */
    public function blockedReason(?CarbonImmutable $on = null): ?string
    {
        $on ??= CarbonImmutable::today(config('app.timezone'));

        $policy = $this->activePolicy($on);

        if ($policy === null) {
            if (! EnvironmentalFeePolicy::query()->exists()) {
                return 'No environmental fee has been configured. A rate and an effective '
                    .'date must be set by the tourism office before anything can be collected.';
            }

            /*
             * SAY WHICH OF THE THREE IT IS.
             *
             * "Check that a policy is switched on and that today falls
             * inside its dates" is true of all of them and useful for
             * none. An officer looking at a page with no fee on it
             * needs to be told what to go and change — an expired end
             * date reads nothing like a policy nobody enabled, and the
             * fix is different in each case.
             */
            $expired = EnvironmentalFeePolicy::query()
                ->where('enabled', true)
                ->whereNotNull('effective_until')
                ->whereDate('effective_until', '<', $on->toDateString())
                ->latest('effective_until')
                ->first();

            if ($expired !== null) {
                return 'The environmental fee stopped applying on '
                    .$expired->effective_until->format('M j, Y')
                    .'. Nothing is being collected and visitors are shown nothing. '
                    .'Configure a new period to start again.';
            }

            $future = EnvironmentalFeePolicy::query()
                ->where('enabled', true)
                ->whereDate('effective_from', '>', $on->toDateString())
                ->oldest('effective_from')
                ->first();

            if ($future !== null) {
                return 'The environmental fee does not start until '
                    .$future->effective_from->format('M j, Y')
                    .'. Nothing is collected before then.';
            }

            return 'An environmental fee has been configured but is switched off. '
                .'Turn it on to begin collecting.';
        }

        return $policy->blockedReason($on);
    }

    /**
     * Work out what a collection would come to, without recording it.
     *
     * On the server, always. The figure a collector sees on a screen is
     * not the figure that gets stored — this one is.
     */
    public function quote(EnvironmentalFeePolicy $policy, int $quantity): string
    {
        $quantity = max(1, $quantity);

        // Centavos, so a rate with an odd half-peso cannot drift.
        $centavos = (int) round((float) $policy->amount * 100) * $quantity;

        return number_format($centavos / 100, 2, '.', '');
    }

    /**
     * Record a fee taken at a checkpoint.
     *
     * Everything is re-checked here even though the form checked it
     * first: a screen left open while the office switched the module off
     * must not be able to write a row.
     *
     * @param  array{quantity?: int|string, receipt_number: string, notes?: ?string, payment_method?: ?string, gcash_reference?: ?string, gcash_sender?: ?string, proof_image?: ?string}  $data
     *
     * @throws EnvironmentalFeeException
     */
    public function record(
        User $collector,
        EnvironmentalFeeCheckpoint $checkpoint,
        array $data,
        ?EnvironmentalFeeCollection $corrects = null,
    ): EnvironmentalFeeCollection {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();

        // 1 and 2: the module is on, and a usable policy exists.
        $policy = $this->activePolicy($today);

        if ($policy === null || ! $policy->isCollectableOn($today)) {
            throw EnvironmentalFeeException::notCollectable(
                $this->blockedReason($today) ?? 'No environmental fee may be collected today.'
            );
        }

        // 3: the checkpoint is one the office still uses.
        if (! $checkpoint->active) {
            throw EnvironmentalFeeException::checkpointInactive($checkpoint->name);
        }

        // 4: this person is authorised HERE, not merely somewhere.
        if (! $checkpoint->isCollectedBy($collector)) {
            throw EnvironmentalFeeException::notAuthorised($checkpoint->name);
        }

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $receipt = Str::squish((string) $data['receipt_number']);

        if ($receipt === '') {
            throw EnvironmentalFeeException::receiptRequired();
        }

        $amount = $this->quote($policy, $quantity);

        /*
         * Cash or GCash, and the difference is not cosmetic.
         *
         * Cash handed over at a gate IS received the moment the receipt
         * is written. A GCash transfer is a claim until somebody opens
         * the app and sees it: this portal has no connection to any
         * wallet and cannot know. So it waits, and it does not count.
         */
        $byGcash = ($data['payment_method'] ?? 'cash') === 'gcash';

        if ($byGcash) {
            $account = EnvironmentalFeeAccount::current();

            if ($account === null || ! $account->isUsable()) {
                throw EnvironmentalFeeException::gcashNotConfigured();
            }

            if (blank($data['gcash_reference'] ?? null)) {
                throw EnvironmentalFeeException::gcashReferenceRequired();
            }
        }

        $gcashReference = $byGcash
            ? Str::squish((string) $data['gcash_reference'])
            : null;

        return DB::transaction(function () use (
            $collector, $checkpoint, $policy, $quantity,
            $receipt, $amount, $data, $now, $corrects,
            $byGcash, $gcashReference
        ): EnvironmentalFeeCollection {
            /*
             * The unique index is the real guard against a receipt
             * number used twice; this check is so a collector gets a
             * sentence instead of a database error.
             */
            $taken = EnvironmentalFeeCollection::query()
                ->where('receipt_number', $receipt)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw EnvironmentalFeeException::receiptUsed($receipt);
            }

            /*
             * One transfer, one fee. Without this, the same GCash
             * reference could be claimed against several receipts and
             * the municipality would record money it was sent once as
             * having arrived three times.
             */
            if ($gcashReference !== null) {
                $claimed = EnvironmentalFeeCollection::query()
                    ->where('gcash_reference', $gcashReference)
                    ->lockForUpdate()
                    ->exists();

                if ($claimed) {
                    throw EnvironmentalFeeException::gcashReferenceUsed($gcashReference);
                }
            }

            $collection = new EnvironmentalFeeCollection;

            $collection->forceFill([
                'reference' => $this->reference($now),
                'receipt_number' => $receipt,

                'checkpoint_id' => $checkpoint->id,
                'collector_id' => $collector->id,
                'policy_id' => $policy->id,

                // Copied from the policy, the way a booking copies its
                // room rate. Changing the fee next month must not change
                // what this receipt says.
                'rate' => $policy->amount,
                'scope' => $policy->scope,
                'currency' => $policy->currency,

                'quantity' => $quantity,
                'amount' => $amount,

                'payment_method' => $byGcash ? 'gcash' : 'cash',

                /*
                 * Cash is received; GCash waits. Nothing here may call a
                 * transfer settled, because nothing here can see one.
                 */
                'payment_status' => $byGcash
                    ? FeePaymentStatus::AwaitingVerification
                    : FeePaymentStatus::Received,

                'gcash_reference' => $gcashReference,
                'gcash_sender' => $byGcash
                    ? (blank($data['gcash_sender'] ?? null)
                        ? null
                        : Str::squish((string) $data['gcash_sender']))
                    : null,
                'proof_image' => $byGcash ? ($data['proof_image'] ?? null) : null,

                'collected_at' => $now,
                'notes' => $data['notes'] ?? null,
                'status' => CollectionStatus::Recorded,
                'corrects_id' => $corrects?->id,
            ])->save();

            // The row being replaced is marked, not deleted, so the trail
            // shows both what was written and what put it right.
            if ($corrects !== null) {
                $corrects->forceFill([
                    'status' => CollectionStatus::Corrected,
                ])->save();
            }

            $this->logger->log(
                $corrects === null
                    ? 'environmental_fee.collected'
                    : 'environmental_fee.corrected',
                $collection,
                "Recorded {$collection->reference} at {$checkpoint->name}",
                [
                    'receipt_number' => $receipt,
                    'checkpoint' => $checkpoint->name,
                    'rate' => (string) $policy->amount,
                    'quantity' => $quantity,
                    'amount' => $amount,
                    'policy_id' => $policy->id,
                    'corrects' => $corrects?->reference,
                    'payment_method' => $byGcash ? 'gcash' : 'cash',
                    'gcash_reference' => $gcashReference,
                ],
                $collector,
            );

            return $collection;
        });
    }

    /**
     * Cancel a collection that should not stand.
     *
     * Never a delete. A receipt that was written and then cancelled is
     * part of the record of what happened at that gate, and a row that
     * simply vanished would be indistinguishable from one that was
     * never written.
     *
     * @throws EnvironmentalFeeException
     */
    public function void(
        EnvironmentalFeeCollection $collection,
        User $actor,
        string $reason,
    ): void {
        $reason = Str::squish($reason);

        if ($reason === '') {
            throw EnvironmentalFeeException::voidNeedsReason();
        }

        DB::transaction(function () use ($collection, $actor, $reason): void {
            $collection->refresh();

            if (! $collection->isVoidable()) {
                throw EnvironmentalFeeException::alreadyClosed(
                    $collection->status->label()
                );
            }

            $collection->forceFill([
                'status' => CollectionStatus::Voided,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->logger->log(
                'environmental_fee.voided',
                $collection,
                "Voided {$collection->reference}",
                [
                    'receipt_number' => $collection->receipt_number,
                    'amount' => (string) $collection->amount,
                    'reason' => $reason,
                ],
                $actor,
            );
        });
    }

    /**
     * Say whether a GCash transfer actually arrived.
     *
     * THE ONLY PLACE a payment is ever called confirmed, and it is
     * always a PERSON saying so. This portal has no connection to a
     * GCash wallet; somebody opens the app, finds the reference, and
     * records what they found. Nothing is automatic and nothing may
     * pretend to be.
     *
     * Rejecting is not voiding. The receipt stands — a fee may well
     * have been owed — but the money did not come, so it stops
     * counting and the office can chase it.
     *
     * @throws EnvironmentalFeeException
     */
    public function settle(
        EnvironmentalFeeCollection $collection,
        User $actor,
        bool $arrived,
        string $note = '',
    ): void {
        $note = Str::squish($note);

        if (! $arrived && $note === '') {
            throw EnvironmentalFeeException::rejectionNeedsReason();
        }

        DB::transaction(function () use ($collection, $actor, $arrived, $note): void {
            $collection->refresh();

            if (! $collection->awaitsVerification()) {
                throw EnvironmentalFeeException::nothingToVerify(
                    $collection->payment_status->label()
                );
            }

            $collection->forceFill([
                'payment_status' => $arrived
                    ? FeePaymentStatus::Verified
                    : FeePaymentStatus::Rejected,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'verification_note' => $note !== '' ? $note : null,
            ])->save();

            $this->logger->log(
                $arrived
                    ? 'environmental_fee.payment_verified'
                    : 'environmental_fee.payment_rejected',
                $collection,
                ($arrived ? 'Confirmed' : 'Could not confirm')
                    ." the GCash payment for {$collection->reference}",
                [
                    'receipt_number' => $collection->receipt_number,
                    'gcash_reference' => $collection->gcash_reference,
                    'amount' => (string) $collection->amount,
                    'note' => $note !== '' ? $note : null,
                ],
                $actor,
            );
        });
    }

    /**
     * Short, unmistakable, and not guessable from the one before it, the
     * way booking and payment references already are.
     */
    private function reference(CarbonImmutable $at): string
    {
        do {
            $reference = 'VDE-'.$at->format('ymd').'-'.Str::upper(Str::random(6));
        } while (
            EnvironmentalFeeCollection::query()->where('reference', $reference)->exists()
        );

        return $reference;
    }
}
