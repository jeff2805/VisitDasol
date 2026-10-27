<?php

namespace App\Support;

/**
 * Peso amounts are formatted in one place so every page looks the same.
 * In Blade use the directive:  @peso($room->price_per_night)
 */
class Money
{
    public static function peso(float|int|string|null $amount, bool $withCentavos = false): string
    {
        return '₱'.number_format((float) $amount, $withCentavos ? 2 : 0);
    }
}
