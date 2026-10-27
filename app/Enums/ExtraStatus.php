<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What the resort said about something a guest asked for.
 *
 * Three answers, and no more. A guest standing on the sand needs to
 * know one thing: is the boat going out or not. Anything between those
 * two is a maybe, and a maybe is what "requested" already means.
 */
enum ExtraStatus: string
{
    use EnumHelpers;

    /** Asked for. The resort has not answered yet. */
    case Requested = 'requested';

    /** The resort has it, and will have it on the day. */
    case Confirmed = 'confirmed';

    /** The resort cannot do it. The reason is on the row. */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Waiting for the resort',
            self::Confirmed => 'Confirmed',
            self::Declined => 'Not available',
        };
    }

    /** Matches the status pill colours used across the site. */
    public function badge(): string
    {
        return match ($this) {
            self::Requested => 'pending',
            self::Confirmed => 'active',
            self::Declined => 'rejected',
        };
    }

    public function isAnswered(): bool
    {
        return $this !== self::Requested;
    }
}
