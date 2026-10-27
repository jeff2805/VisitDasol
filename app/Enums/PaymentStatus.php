<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where one payment attempt got to. Stored in payments.status.
 *
 *   pending -> paid | failed | cancelled
 *   paid    -> refunded
 *
 * Only the gateway may move a row to paid: PaymentService sets it from the
 * gateway's own answer, never from anything the browser sends back.
 */
enum PaymentStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Cancelled => 'Payment cancelled',
            self::Refunded => 'Refunded',
        };
    }

    /** True once the money is actually with the resort's account. */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }

    /** True while this attempt could still turn into a payment. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
