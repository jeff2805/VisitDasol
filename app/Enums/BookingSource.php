<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * How a reservation reached the system. Stored in bookings.booking_source.
 *
 * Online is a guest who booked themselves through this site and holds an
 * account. Walk-in is a guest the resort met at its own gate and recorded
 * afterwards, who may have no account at all — which is why bookings.user_id
 * is nullable and every screen falls back to contact_name.
 *
 * THIS IS A RECORD OF ORIGIN, NOT A DIFFERENT KIND OF BOOKING. A walk-in
 * holds inventory, is priced, and is reported exactly as an online stay is;
 * AvailabilityService never asks which of the two it is looking at. The one
 * place the difference matters is the Department of Tourism return, where a
 * walk-in counts only once the tourism office has accepted it — see
 * WalkInSubmissionStatus and ReportService::accommodationRegister().
 */
enum BookingSource: string
{
    use EnumHelpers;

    case Online = 'online';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Booked online',
            self::WalkIn => 'Walk-in',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Online => 'The guest booked through this site.',
            self::WalkIn => 'The resort recorded this guest at its own gate.',
        };
    }

    /** Whether this reservation has to be accepted before it is reported. */
    public function needsTourismReview(): bool
    {
        return $this === self::WalkIn;
    }
}
