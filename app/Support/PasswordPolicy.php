<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;

/**
 * What counts as an acceptable password here, in one place.
 *
 * It was in three: AppServiceProvider enforced min(8)->letters()->numbers(),
 * four Blade templates each spelled out "At least 8 characters, including
 * letters and numbers" by hand, and the settings screen offered the office
 * four boxes — length, upper and lower case, a number, a symbol — that were
 * stored and never read. The screen's own defaults said upper and lower
 * case was required. It was not. An office reading that screen would have
 * told the panel something about this system that was untrue.
 *
 * So the rule and the sentence describing it are both built from the same
 * settings, and neither can drift from the other or from what the office
 * was shown.
 */
class PasswordPolicy
{
    /**
     * The validation rule, read fresh each time.
     *
     * Password::defaults() is given a closure rather than a built rule,
     * so a change the office saves applies on the next form submitted
     * without a deploy or a cache clear.
     */
    public static function rule(): Password
    {
        $rule = Password::min(self::minimumLength())->letters();

        if (self::requires('mixed_case')) {
            $rule->mixedCase();
        }

        if (self::requires('numbers')) {
            $rule->numbers();
        }

        if (self::requires('symbols')) {
            $rule->symbols();
        }

        return $rule;
    }

    /**
     * The same policy as a sentence, for the hint under the password box.
     *
     * Written out rather than hardcoded in the templates because a hint
     * that disagrees with the rule is worse than no hint: the visitor
     * does what it says and the form refuses them anyway, with no way to
     * tell which of the two is lying.
     */
    public static function describe(): string
    {
        $needs = [];

        $needs[] = self::requires('mixed_case')
            ? 'an upper and a lower case letter'
            : 'a letter';

        if (self::requires('numbers')) {
            $needs[] = 'a number';
        }

        if (self::requires('symbols')) {
            $needs[] = 'a symbol (for example ! or -)';
        }

        return 'At least '.self::minimumLength().' characters, including '
            .Arr::join($needs, ', ', ' and ').'.';
    }

    /**
     * Eight is the floor, whatever the stored setting says.
     *
     * The settings screen already refuses anything lower, but a figure
     * edited straight into the database, or an older row from before that
     * rule existed, must not be able to weaken every password on the site.
     */
    public static function minimumLength(): int
    {
        return max(8, (int) config('visitdasol.auth.password.min', 8));
    }

    private static function requires(string $key): bool
    {
        return (bool) config('visitdasol.auth.password.'.$key, true);
    }
}
