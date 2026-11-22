<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses an email address whose domain is a near-miss of a common one:
 * gmail.con, gmial.com, yaho.com, hotmial.com, outlook.con.
 *
 * WHY NOT A DNS LOOKUP, which is the obvious answer. It is worse on both
 * sides. It MISSES gmial.com, which resolves — somebody registered it
 * precisely because people mistype it. And it REFUSES real addresses any
 * time the server cannot reach a resolver, turning a network hiccup into
 * "nobody can register today". It would also refuse this site's own
 * mto@visitdasol.test, which is why the test suite could not run with it.
 *
 * So this compares the domain against a short list of the mail providers
 * visitors actually use, and refuses anything within an edit or two of
 * one without being it. A domain nowhere near the list passes untouched:
 * a resort's own company address is not this rule's business.
 *
 * It names what it thinks you meant. "Invalid email" shown against an
 * address that looks right to the person who typed it is the kind of
 * error people retype unchanged instead of fixing.
 */
class NotAMistypedEmailDomain implements ValidationRule
{
    /**
     * The providers worth guarding, plus the look-alikes that are real.
     *
     * Short on purpose. Every name here is a domain whose neighbourhood
     * this rule then closes off, so a longer list is not a safer one — it
     * is more chances to refuse somebody's genuine address.
     *
     * Some entries are here only to be matched EXACTLY, because they sit
     * one edit from an entry above and are themselves real. protonmail.ch
     * is Proton's own Swiss domain, a single edit from protonmail.com.
     * mail.com is a free provider older than most of this list and a
     * single edit from gmail.com. Both were refused as typos of their
     * neighbours until they were named here.
     *
     * @var list<string>
     */
    private const KNOWN = [
        'gmail.com',
        'googlemail.com',
        'mail.com',
        'yahoo.com',
        'yahoo.com.ph',
        'ymail.com',
        'rocketmail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'icloud.com',
        'protonmail.com',
        'protonmail.ch',
        'proton.me',
        'aol.com',
        'msn.com',
        'me.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $at = strrpos($value, '@');

        if ($at === false) {
            return; // Not an address at all; the email rule reports that.
        }

        $domain = mb_strtolower(trim(substr($value, $at + 1)));

        if ($domain === '' || in_array($domain, self::KNOWN, true)) {
            return;
        }

        foreach (self::KNOWN as $known) {
            $allowed = $this->editsAllowed($known);

            if ($allowed === 0) {
                continue;
            }

            if (levenshtein($domain, $known) <= $allowed
                || $this->isSwappedPair($domain, $known)) {
                $fail(
                    'That email address looks mistyped: there is no mail'
                    ." provider at \"{$domain}\". Did you mean \"{$known}\"?"
                );

                return;
            }
        }
    }

    /**
     * Whether the two differ only by two neighbouring letters changing
     * places: gmial.com for gmail.com, hotmial.com for hotmail.com.
     *
     * Asked separately because levenshtein() scores a swap as two edits —
     * a deletion and an insertion — and so filed gmial.com as being as
     * far from gmail.com as a word with two unrelated mistakes in it.
     * Raising the edit allowance to cover it would have swept in real
     * domains by the dozen. A swap is one slip of two fingers in the
     * wrong order, and deserves to be counted as one.
     */
    private function isSwappedPair(string $domain, string $known): bool
    {
        if (strlen($domain) !== strlen($known) || $domain === $known) {
            return false;
        }

        $differences = [];

        for ($i = 0, $length = strlen($known); $i < $length; $i++) {
            if ($domain[$i] !== $known[$i]) {
                $differences[] = $i;

                if (count($differences) > 2) {
                    return false;
                }
            }
        }

        if (count($differences) !== 2) {
            return false;
        }

        [$first, $second] = $differences;

        return $second - $first === 1
            && $domain[$first] === $known[$second]
            && $domain[$second] === $known[$first];
    }

    /**
     * How far from a known domain still counts as a typo of it.
     *
     * Scaled by length, because an edit means less in a long word. Two
     * edits from me.com reaches my.com and we.com, both real; two from
     * protonmail.com reaches nothing anybody uses. So the short names on
     * the list are matched exactly and guard nothing — they are there to
     * be allowed, not to catch misspellings of themselves.
     */
    private function editsAllowed(string $known): int
    {
        return match (true) {
            mb_strlen($known) > 10 => 2,
            mb_strlen($known) >= 8 => 1,
            default => 0,
        };
    }
}
