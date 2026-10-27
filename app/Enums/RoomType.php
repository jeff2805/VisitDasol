<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Room category. Stored in rooms.room_type (a varchar, not a database enum,
 * so changing this list needs no schema change).
 *
 * Three, on purpose. The list once carried standard, deluxe, family, villa
 * and other as well, and the result was rooms filed wrongly: a ₱600 room sat
 * under "cottage" because its owner could not find anything better, and
 * "deluxe" and "premium" are the same word to most people. Resorts around
 * Dasol let a cheap room, a good room, or a cottage. A list that matches how
 * owners actually talk gets filled in correctly; a longer one only offers
 * more ways to be wrong.
 *
 * Anything removed was carried over to one of these by the
 * narrow_room_categories migration, so no stored row can hold a value this
 * enum cannot read.
 *
 * Ordered by price, cheapest first, because that is the order the category
 * menu shows them in.
 */
enum RoomType: string
{
    use EnumHelpers;

    case Budget = 'budget';
    case Premium = 'premium';
    case Cottage = 'cottage';

    public function label(): string
    {
        return match ($this) {
            self::Budget => 'Budget',
            self::Premium => 'Premium',
            self::Cottage => 'Cottage',
        };
    }

    /**
     * A line to help an owner choose, shown under the category menu.
     *
     * Without these the choice is a guess, and a guess is how a ₱600 room
     * ended up filed as a cottage.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Budget => 'your plainer, cheaper room',
            self::Premium => 'your better room, at your higher rate',
            self::Cottage => 'a separate hut or cabin, not a room in a building',
        };
    }
}
