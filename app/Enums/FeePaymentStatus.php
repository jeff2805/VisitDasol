<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Whether the municipality actually has the money.
 *
 * Separate from CollectionStatus, which says what the RECORD is. A
 * receipt can be perfectly valid and the transfer behind it still not
 * have arrived.
 *
 * Cash handed over at a gate is Received the moment it is written down.
 * A GCash transfer is not: this portal has no connection to anybody's
 * wallet, cannot see a payment land, and must not pretend otherwise. It
 * waits for a person to open the app and confirm.
 */
enum FeePaymentStatus: string
{
    use EnumHelpers;

    case Received = 'received';
    case AwaitingVerification = 'awaiting_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Cash received',
            self::AwaitingVerification => 'Waiting to be checked',
            self::Verified => 'GCash confirmed',
            self::Rejected => 'Not received',
        };
    }

    /**
     * Whether this money may be added to a total.
     *
     * A transfer nobody has checked is not money the municipality
     * holds, and counting it would report funds that may never have
     * been sent.
     */
    public function counts(): bool
    {
        return $this === self::Received || $this === self::Verified;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Received, self::Verified => 'active',
            self::AwaitingVerification => 'pending',
            self::Rejected => 'inactive',
        };
    }

    /** Still waiting on somebody to look at the GCash app. */
    public function isOpen(): bool
    {
        return $this === self::AwaitingVerification;
    }
}
