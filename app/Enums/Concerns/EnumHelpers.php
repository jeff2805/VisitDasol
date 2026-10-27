<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for the project's string-backed enums.
 * Each enum using this trait must define label().
 */
trait EnumHelpers
{
    /**
     * All stored values, e.g. for validation: Rule::in(RoomType::values()).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * value => label pairs for <select> menus.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
