<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What a resort asks for when the guest is arriving very soon.
 *
 * A deposit with a 24-hour deadline is no use on a booking made the night
 * before arrival: the deadline would fall after the guest is already at
 * the gate. Resorts answer that case separately, and this is the answer.
 */
enum LastMinuteRule: string
{
    use EnumHelpers;

    case Full = 'full';
    case Deposit = 'deposit';
    case OnSite = 'on_site';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Ask for the full amount',
            self::Deposit => 'Ask for the usual deposit',
            self::OnSite => 'Let them pay at the resort',
        };
    }

    /** The requirement this rule imposes, in the ordinary vocabulary. */
    public function requirement(): PaymentRequirement
    {
        return match ($this) {
            self::Full => PaymentRequirement::Full,
            self::Deposit => PaymentRequirement::Deposit,
            self::OnSite => PaymentRequirement::None,
        };
    }
}
