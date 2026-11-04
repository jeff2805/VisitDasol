<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where a booking's valid ID has got to.
 *
 *   not_submitted -> pending
 *   pending       -> verified | rejected
 *   rejected      -> pending        (the guest sends another)
 *   verified is final for this booking
 *
 * Stored in bookings.id_document_status. Only Verified lets a party check
 * in; the other three are the reasons the front desk is told no.
 */
enum IdVerificationStatus: string
{
    use EnumHelpers;

    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Not submitted',
            self::Pending => 'Pending verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Why check-in cannot go ahead, or null when it can.
     *
     * The wording is the front desk's to read out, so each one says what
     * has to happen next rather than only what is wrong.
     */
    public function blocksCheckInBecause(): ?string
    {
        return match ($this) {
            self::NotSubmitted => 'This guest has not submitted a valid ID yet. '
                .'Ask them to upload one from their reservation page before checking in.',

            self::Pending => 'This guest\'s valid ID has not been verified yet. '
                .'Review the submitted ID and approve it before checking in.',

            self::Rejected => 'This guest\'s valid ID was rejected. '
                .'Ask them to submit a clearer or different government-issued ID.',

            self::Verified => null,
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
