<?php

namespace App\Services;

/**
 * What one stay costs, line by line.
 *
 * Every amount is a string with two decimals, worked out in centavos,
 * so it can be stored and compared without the drift a float brings.
 * The parts always add up: childDiscount + pwdDiscount = discountTotal,
 * and subtotal - discountTotal = total.
 */
final class PriceBreakdown
{
    public function __construct(
        public readonly string $subtotal,
        public readonly string $childDiscount,
        public readonly string $pwdDiscount,
        public readonly string $discountTotal,
        public readonly string $total,

        /** The rates that produced the amounts above, 0 when not applied. */
        public readonly int $childPercent,
        public readonly int $pwdPercent,

        /** How many guests each rate was charged against. */
        public readonly int $childrenDiscounted,
        public readonly int $pwdDiscounted,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discountTotal !== '0.00';
    }
}
