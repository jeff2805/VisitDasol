<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Whether a review is on show. Stored in reviews.status.
 *
 * Reviews are published as they are written and taken down afterwards if they
 * need to be, rather than being held in a queue. A municipal office cannot
 * read every review the moment it arrives, and a portal where honest reviews
 * sit invisible for days is worse than one that moderates after the fact.
 */
enum ReviewStatus: string
{
    use EnumHelpers;

    case Published = 'published';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Hidden => 'Hidden',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Published;
    }
}
