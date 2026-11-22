<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * The standing of one accreditation on the office's list.
 *
 * Stored in accredited_resorts.accreditation_status as a varchar, so the
 * list can gain a case without a schema change.
 */
enum AccreditationStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether an accreditation in this state is one the office would
     * currently stand behind.
     *
     * A resort whose accreditation lapsed is still ON the list — removing
     * it would lose the record — but a match against it must not be
     * reported as a clean "listed". The reviewer has to see the lapse.
     */
    public function isCurrent(): bool
    {
        return $this === self::Active;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'text-bg-success',
            self::Expired => 'text-bg-secondary',
            self::Suspended => 'text-bg-warning',
        };
    }
}
