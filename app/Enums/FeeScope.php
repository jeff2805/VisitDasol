<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * What an environmental fee is charged against.
 *
 * Three ways a municipality might count, not a claim about how Dasol
 * counts. The ordinance decides, and the office records the answer.
 */
enum FeeScope: string
{
    use EnumHelpers;

    case PerVisitor = 'per_visitor';
    case PerGroup = 'per_group';
    case PerVehicle = 'per_vehicle';

    public function label(): string
    {
        return match ($this) {
            self::PerVisitor => 'For each visitor',
            self::PerGroup => 'For each group',
            self::PerVehicle => 'For each vehicle',
        };
    }

    /** What the quantity on a receipt is counting. */
    public function unit(): string
    {
        return match ($this) {
            self::PerVisitor => 'visitor',
            self::PerGroup => 'group',
            self::PerVehicle => 'vehicle',
        };
    }
}
