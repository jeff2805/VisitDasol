<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Concerns\HasTransitions;

/**
 * Account status. Stored in users.status. Only "active" accounts may sign in.
 *
 * Resort owners:  pending -> active | rejected
 *                 active <-> inactive
 *                 rejected -> pending   (MTO reopens the application)
 * Tourists use only active <-> inactive (enforced by policy in Phase 4).
 */
enum UserStatus: string
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
            self::Active => [self::Inactive],
            self::Inactive => [self::Active],
            self::Rejected => [self::Pending],
        };
    }

    /**
     * Message shown when a signed-in attempt is refused (Phase 3).
     */
    public function loginMessage(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Pending => 'Your application is still being reviewed by the Municipal Tourism Office. You can sign in once it is approved.',
            self::Rejected => 'Your application was not approved. Please contact the Municipal Tourism Office for details.',
            self::Inactive => 'This account has been deactivated. Please contact the Municipal Tourism Office.',
        };
    }
}
