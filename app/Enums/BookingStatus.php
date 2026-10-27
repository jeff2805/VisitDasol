<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Concerns\HasTransitions;

/**
 * Reservation status. Stored in bookings.status.
 *
 *   pending   -> confirmed | rejected | cancelled
 *   confirmed -> cancelled | completed
 *   rejected, cancelled, completed are final.
 *
 * Who may make each change, and when, is decided by BookingPolicy (Phase 11+).
 */
enum BookingStatus: string
{
    use EnumHelpers;
    use HasTransitions;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Rejected, self::Cancelled],
            self::Confirmed => [self::Cancelled, self::Completed],
            self::Rejected, self::Cancelled, self::Completed => [],
        };
    }

    /**
     * Statuses that always hold room inventory.
     * Pending bookings also hold inventory until their check-in date passes
     * (see Booking::scopeBlocking).
     *
     * @return list<self>
     */
    public static function firmStatuses(): array
    {
        return [self::Confirmed, self::Completed];
    }
}
