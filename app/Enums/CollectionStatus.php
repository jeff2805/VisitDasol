<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where one recorded collection stands.
 *
 * Note what is absent: there is no "paid" or "verified". A row here
 * records that a collector wrote a receipt at a gate. The portal has no
 * payment gateway for municipal fees, so it cannot and does not claim
 * that money cleared, was remitted, or was deposited.
 */
enum CollectionStatus: string
{
    use EnumHelpers;

    case Recorded = 'recorded';
    case Voided = 'voided';
    case Corrected = 'corrected';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Voided => 'Voided',
            self::Corrected => 'Corrected',
        };
    }

    /** Whether this row still counts towards a total. */
    public function counts(): bool
    {
        return $this === self::Recorded;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Recorded => 'active',
            self::Voided => 'inactive',
            self::Corrected => 'pending',
        };
    }
}
