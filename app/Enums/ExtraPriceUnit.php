<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What an extra's price is charged against.
 *
 * The one thing that most often goes wrong on a resort bill: a boat
 * priced for the whole boat, charged per head, turns a family of six
 * into six times the money. So the unit is stored beside the price and
 * printed beside it everywhere.
 */
enum ExtraPriceUnit: string
{
    use EnumHelpers;

    /** One charge, however many people or hours. Island hopping, a boat. */
    case PerBooking = 'per_booking';

    /** Each person pays. A banana boat ride, a buffet. */
    case PerPerson = 'per_person';

    /** By the hour. Kayak and paddleboard hire. */
    case PerHour = 'per_hour';

    /** By the day. A cottage fan, a tent, a cooler. */
    case PerDay = 'per_day';

    public function label(): string
    {
        return match ($this) {
            self::PerBooking => 'For the whole booking',
            self::PerPerson => 'Per person',
            self::PerHour => 'Per hour',
            self::PerDay => 'Per day',
        };
    }

    /** The short form that sits after a price: "₱500 per person". */
    public function suffix(): string
    {
        return match ($this) {
            self::PerBooking => 'for the booking',
            self::PerPerson => 'per person',
            self::PerHour => 'per hour',
            self::PerDay => 'per day',
        };
    }

    /** What the guest is counting when they pick a number. */
    public function quantityLabel(): string
    {
        return match ($this) {
            self::PerBooking => 'How many',
            self::PerPerson => 'How many people',
            self::PerHour => 'How many hours',
            self::PerDay => 'How many days',
        };
    }
}
