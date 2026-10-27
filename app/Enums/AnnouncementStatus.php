<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Announcement visibility. Stored in announcements.status.
 */
enum AnnouncementStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
