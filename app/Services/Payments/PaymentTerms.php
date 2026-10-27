<?php

namespace App\Services\Payments;

use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use Carbon\CarbonImmutable;

/**
 * What one reservation has to pay, and by when.
 *
 * Money is carried as strings with two decimals, worked out in centavos
 * upstream, so nothing here can drift the way a float would.
 *
 * dueAt may legitimately be null: a deadline counted from confirmation
 * cannot be known while the owner is still deciding. The booking records
 * the reference event, and BookingStatusManager fills the date in when
 * the owner accepts.
 */
final class PaymentTerms
{
    public function __construct(
        public readonly PaymentRequirement $requirement,
        public readonly int $depositPercent,

        /** The whole bill, discounts already taken off. */
        public readonly string $total,

        /** What has to reach the resort before arrival. */
        public readonly string $dueNow,

        /** What is left to settle afterwards. */
        public readonly string $balance,

        public readonly ?CarbonImmutable $dueAt,
        public readonly PaymentDeadlineReference $dueReference,
        public readonly int $deadlineHours,

        /** Whether the resort's last-minute rule decided these terms. */
        public readonly bool $lastMinute,

        public readonly bool $onSiteAllowed,
        public readonly int $policyVersion,
    ) {}

    /** Whether anything has to be paid before the guest arrives. */
    public function needsAdvancePayment(): bool
    {
        return $this->dueNow !== '0.00';
    }

    /**
     * Whether the deadline is still waiting on the owner's decision.
     * The booking form says so rather than showing a blank date.
     */
    public function awaitsConfirmation(): bool
    {
        return $this->dueAt === null
            && $this->dueReference->waitsForConfirmation();
    }

    /**
     * The terms in one sentence, for the booking form and the
     * reservation page. Written here so the three screens that show it
     * cannot drift apart.
     */
    public function sentence(): string
    {
        if (! $this->needsAdvancePayment()) {
            return $this->onSiteAllowed
                ? 'Nothing to pay now. Settle the whole amount at the resort.'
                : 'Nothing to pay now. The resort will tell you how to settle.';
        }

        $what = $this->requirement === PaymentRequirement::Full
            ? 'the full amount'
            : "a {$this->depositPercent}% deposit";

        $when = $this->awaitsConfirmation()
            ? "within {$this->deadlineHours} hours of the resort confirming"
            : ($this->dueAt
                ? 'by '.$this->dueAt->format('M j, Y g:i A')
                : 'once the resort confirms');

        $lead = $this->lastMinute
            ? "Because you are arriving soon, this resort asks for {$what}"
            : "This resort asks for {$what}";

        return "{$lead}, {$when}.";
    }

    /**
     * The snapshot stored on the booking.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'payment_requirement' => $this->requirement->value,
            'payment_deposit_percent' => $this->depositPercent,
            'amount_due_now' => $this->dueNow,
            'payment_due_at' => $this->dueAt,
            'payment_due_reference' => $this->dueReference->value,
            'payment_deadline_hours' => $this->deadlineHours,
            'payment_last_minute' => $this->lastMinute,
            'payment_on_site_allowed' => $this->onSiteAllowed,
            'payment_policy_version' => $this->policyVersion,
        ];
    }
}
