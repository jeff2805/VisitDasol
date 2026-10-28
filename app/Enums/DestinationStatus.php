<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Destination visibility. Stored in destinations.status.
 *
 * Only Active is public.
 *
 *   active -> inactive   (the office takes it down)
 *   inactive -> active   (the office puts it back up)
 *
 * There were two more states, Pending and Rejected, for spots sent in
 * by a barangay official and waiting on the office. That role and its
 * review queue are gone: the Municipal Tourism Office now writes up
 * destinations itself, and a destination it has written is published or
 * hidden, never in between.
 */
enum DestinationStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Published',
            self::Inactive => 'Hidden',
        };
    }
}
