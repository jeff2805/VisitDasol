<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What a payment deadline is counted from.
 *
 * "So many hours" is meaningless without saying from when, and the three
 * answers below behave differently:
 *
 *   Confirmation  the clock cannot start until the owner accepts, so the
 *                 deadline is unknown while a request is still waiting.
 *   Booking       the clock starts the moment the request is sent, so the
 *                 deadline is known straight away.
 *   CheckIn       counted BACKWARDS from arrival, so the deadline is known
 *                 straight away and may already have passed on a booking
 *                 made at the last minute.
 */
enum PaymentDeadlineReference: string
{
    use EnumHelpers;

    case Confirmation = 'confirmation';
    case Booking = 'booking';
    case CheckIn = 'check_in';

    public function label(): string
    {
        return match ($this) {
            self::Confirmation => 'After you confirm the booking',
            self::Booking => 'After the guest sends the request',
            self::CheckIn => 'Before the guest arrives',
        };
    }

    /** How the deadline reads to the guest once it is known. */
    public function sentence(): string
    {
        return match ($this) {
            self::Confirmation => 'after the resort confirms',
            self::Booking => 'after you send this request',
            self::CheckIn => 'before you arrive',
        };
    }

    /**
     * Whether the deadline can only be worked out once the owner has
     * accepted the request.
     */
    public function waitsForConfirmation(): bool
    {
        return $this === self::Confirmation;
    }
}
