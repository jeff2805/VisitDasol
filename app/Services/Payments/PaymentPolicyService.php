<?php

namespace App\Services\Payments;

use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use App\Models\Booking;
use App\Models\ResortPaymentPolicy;
use Carbon\CarbonImmutable;

/**
 * Works out what a reservation must pay up front, and by when.
 *
 * The only place those two questions are answered. The booking form shows
 * what this returns, BookingService stores what this returns, and the
 * reservation page reads the stored copy back — so a guest cannot be
 * quoted one figure and charged another.
 *
 * Two rules shape everything here:
 *
 *   1. A deposit is a share of the bill AFTER the child and PWD discounts,
 *      because that is the bill. Taking a percentage of the undiscounted
 *      total would quietly undo the discount the guest was promised.
 *
 *   2. An imminent arrival is governed by the resort's last-minute rule
 *      instead of its ordinary one. A 24-hour deadline on a booking made
 *      the night before arrival would fall due after the guest is already
 *      at the gate.
 */
class PaymentPolicyService
{
    /**
     * The terms for a stay priced at $total, arriving on $checkIn.
     *
     * $now is injectable so the last-minute rule can be tested without
     * waiting for the clock.
     */
    public function terms(
        ResortPaymentPolicy $policy,
        float|string $total,
        CarbonImmutable $checkIn,
        ?CarbonImmutable $now = null,
    ): PaymentTerms {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        $totalCentavos = max(0, (int) round((float) $total * 100));

        /*
         * Is the guest arriving inside the resort's last-minute window?
         * Measured to the START of the arrival day, because that is when
         * the resort has to have the room ready and the money settled.
         */
        $lastMinute = $checkIn->startOfDay()
            ->lte($now->addHours(max(0, $policy->last_minute_hours)));

        $requirement = $lastMinute
            ? $policy->last_minute_rule->requirement()
            : $policy->requirement;

        $percent = max(1, min(100, (int) $policy->deposit_percent));

        $dueCentavos = match ($requirement) {
            PaymentRequirement::None => 0,
            PaymentRequirement::Full => $totalCentavos,

            // Truncated, so a deposit that does not divide evenly is never
            // rounded up past what the guest was shown.
            PaymentRequirement::Deposit => intdiv($totalCentavos * $percent, 100),
        };

        // A "deposit" that came to the whole bill is a full payment, and
        // saying so is clearer than quoting a percentage that equals 100.
        if ($dueCentavos >= $totalCentavos && $totalCentavos > 0) {
            $dueCentavos = $totalCentavos;
            $requirement = PaymentRequirement::Full;
        }

        $reference = $policy->deadline_reference;
        $hours = max(0, (int) $policy->deadline_hours);

        return new PaymentTerms(
            requirement: $requirement,
            depositPercent: $requirement === PaymentRequirement::Deposit ? $percent : 0,
            total: $this->money($totalCentavos),
            dueNow: $this->money($dueCentavos),
            balance: $this->money($totalCentavos - $dueCentavos),
            dueAt: $dueCentavos > 0
                ? $this->deadline($reference, $hours, $checkIn, $now)
                : null,
            dueReference: $reference,
            deadlineHours: $hours,
            lastMinute: $lastMinute,
            onSiteAllowed: (bool) $policy->allow_on_site,
            policyVersion: (int) $policy->version,
        );
    }

    /**
     * The terms a booking was actually made under, read back from its own
     * snapshot rather than recalculated.
     *
     * A booking made before this feature existed has no snapshot, and is
     * reported as owing the whole amount with no deadline — which is how
     * the portal behaved when it was made.
     */
    public function stored(Booking $booking): PaymentTerms
    {
        $total = (string) $booking->total_amount;

        if ($booking->amount_due_now === null) {
            return new PaymentTerms(
                requirement: PaymentRequirement::Full,
                depositPercent: 0,
                total: $total,
                dueNow: $total,
                balance: '0.00',
                dueAt: null,
                dueReference: PaymentDeadlineReference::Confirmation,
                deadlineHours: 0,
                lastMinute: false,
                onSiteAllowed: true,
                policyVersion: 0,
            );
        }

        $totalCentavos = (int) round((float) $total * 100);
        $dueCentavos = min($totalCentavos, (int) round((float) $booking->amount_due_now * 100));

        /*
         * The booking casts these to enums, but a row written before the
         * casts existed still holds a plain string, so both are accepted.
         */
        return new PaymentTerms(
            requirement: $booking->payment_requirement instanceof PaymentRequirement
                ? $booking->payment_requirement
                : PaymentRequirement::Full,
            depositPercent: (int) $booking->payment_deposit_percent,
            total: $total,
            dueNow: $this->money($dueCentavos),
            balance: $this->money($totalCentavos - $dueCentavos),
            dueAt: $booking->payment_due_at
                ? CarbonImmutable::parse($booking->payment_due_at)
                : null,
            dueReference: $booking->payment_due_reference instanceof PaymentDeadlineReference
                ? $booking->payment_due_reference
                : PaymentDeadlineReference::Confirmation,
            deadlineHours: (int) $booking->payment_deadline_hours,
            lastMinute: (bool) $booking->payment_last_minute,
            onSiteAllowed: (bool) $booking->payment_on_site_allowed,
            policyVersion: (int) $booking->payment_policy_version,
        );
    }

    /**
     * When the money is due.
     *
     * Null while the clock has not started: a deadline counted from
     * confirmation is unknowable until the owner accepts, and inventing
     * one from the booking date would be a date the guest never agreed to.
     *
     * A deadline counted back from arrival can land in the past on a
     * late booking. It is returned as it falls rather than nudged
     * forward — the resort's own last-minute rule is what is meant to
     * handle that case, and quietly moving the date would hide a policy
     * the owner should fix.
     */
    private function deadline(
        PaymentDeadlineReference $reference,
        int $hours,
        CarbonImmutable $checkIn,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        return match ($reference) {
            PaymentDeadlineReference::Confirmation => null,
            PaymentDeadlineReference::Booking => $now->addHours($hours),
            PaymentDeadlineReference::CheckIn => $checkIn->startOfDay()->subHours($hours),
        };
    }

    /**
     * The deadline for a booking the owner has just confirmed.
     *
     * Only ever fills a blank: a date already on the booking is the date
     * the guest was shown, and re-deriving it here would move a deadline
     * somebody is working to.
     */
    public function deadlineOnConfirmation(Booking $booking): ?CarbonImmutable
    {
        if ($booking->payment_due_at !== null || $booking->amount_due_now === null) {
            return null;
        }

        if ((float) $booking->amount_due_now <= 0) {
            return null;
        }

        $reference = $booking->payment_due_reference;

        if (
            ! $reference instanceof PaymentDeadlineReference
            || ! $reference->waitsForConfirmation()
        ) {
            return null;
        }

        return CarbonImmutable::now(config('app.timezone'))
            ->addHours(max(0, (int) $booking->payment_deadline_hours));
    }

    private function money(int $centavos): string
    {
        return number_format(max(0, $centavos) / 100, 2, '.', '');
    }
}
