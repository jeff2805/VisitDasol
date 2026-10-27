<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Room type availability switch. Stored in rooms.status.
 */
enum RoomStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Available for booking',
            self::Inactive => 'Not bookable',
        };
    }
}
