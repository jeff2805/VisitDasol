<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Concerns\HasTransitions;

/**
 * Resort listing status. Stored in resorts.status.
 * Only "active" resorts (with an active owner) are public and bookable.
 *
 *   pending  -> active | rejected   (MTO review)
 *   rejected -> pending             (owner resubmits)
 *   active  <-> inactive            (MTO)
 */
enum ResortStatus: string
{
    use EnumHelpers;
    use HasTransitions;

    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Rejected],
            self::Rejected => [self::Pending],
            self::Active => [self::Inactive],
            self::Inactive => [self::Active],
        };
    }
}
