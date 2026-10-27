<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What a guest is actually buying: a night's sleep, or the use of a
 * room for part of a day.
 *
 * Day and night tours are how most of Dasol's resorts sell to visitors
 * who are not staying over — a family at the beach from breakfast to
 * sunset, or a group who come after dark. They are priced per booking,
 * not per night, which is why nights is zero for both.
 *
 * ALL THREE HOLD THE ROOM FOR A WHOLE DATE. A tour does not sleep in
 * the unit, but it is in it, and a resort cannot hand the same cottage
 * to a day group and an overnight party at the same time. The engine
 * therefore blocks the date either way; see BookingType::nights().
 */
enum BookingType: string
{
    use EnumHelpers;

    case Overnight = 'overnight';
    case DayTour = 'day_tour';
    case NightTour = 'night_tour';

    public function label(): string
    {
        return match ($this) {
            self::Overnight => 'Overnight stay',
            self::DayTour => 'Day tour',
            self::NightTour => 'Night tour',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Overnight => 'Sleep over. Priced for each night.',
            self::DayTour => 'Use of the room for the day. No overnight stay.',
            self::NightTour => 'Use of the room for the evening, leaving the next morning.',
        };
    }

    /** Whether the guest is sleeping there, and so paying per night. */
    public function isOvernight(): bool
    {
        return $this === self::Overnight;
    }

    /** A tour is one date, so the form asks for one date. */
    public function isTour(): bool
    {
        return $this !== self::Overnight;
    }

    /**
     * The hours a resort keeps for this, when it has not said otherwise.
     *
     * A night tour's departure is the following morning, which is why
     * its checkout hour is earlier than its arrival hour.
     *
     * @return array{0: string, 1: string}
     */
    public function defaultHours(): array
    {
        return match ($this) {
            self::Overnight => ['14:00', '12:00'],
            self::DayTour => ['08:00', '17:00'],
            self::NightTour => ['18:00', '06:00'],
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Overnight => 'moon-stars',
            self::DayTour => 'sun',
            self::NightTour => 'stars',
        };
    }
}
