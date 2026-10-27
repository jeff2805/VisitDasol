<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * How much a resort asks for up front. Stored on the resort's policy and
 * snapshotted onto every booking made under it.
 *
 * None is a real answer, not an absence of one: plenty of small resorts
 * in Dasol take a name and a phone number and settle the whole bill when
 * the guest arrives. The portal must be able to say so rather than
 * inventing a deposit nobody asked for.
 */
enum PaymentRequirement: string
{
    use EnumHelpers;

    case None = 'none';
    case Deposit = 'deposit';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No advance payment',
            self::Deposit => 'A deposit',
            self::Full => 'The full amount',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'Guests reserve without paying anything now. You collect on arrival.',
            self::Deposit => 'Guests pay a share of the bill to hold the booking, and the rest later.',
            self::Full => 'Guests pay the whole amount before they arrive.',
        };
    }

    /** Whether anything at all has to reach the resort before arrival. */
    public function needsAdvancePayment(): bool
    {
        return $this !== self::None;
    }
}
