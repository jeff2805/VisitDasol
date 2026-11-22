<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns the words people actually type into something comparable.
 *
 * Both sides of the accreditation check pass through here — the office's
 * uploaded spreadsheet when it is imported, and the applicant's own
 * details when they are matched. That is the point of the class: if the
 * two sides normalised separately they would drift, and the bug would
 * show up as "the office says they uploaded it and the system cannot
 * find it", which is almost impossible to diagnose from a screenshot.
 *
 * None of this is clever. It is a short list of the specific ways the
 * same resort gets written down differently in Dasol.
 */
final class AccreditationKey
{
    /**
     * Words that carry no identity in a resort name here.
     *
     * Nearly every business on the list is a "resort" or a "beach
     * resort", so those words separate nothing — while their presence or
     * absence is the single most common difference between the office's
     * spelling and the owner's. "ABC Resort" and "ABC Beach Resort" are
     * one business; without this they are two.
     *
     * Kept short on purpose. Every word here is one the matcher stops
     * being able to tell apart, so a long list eventually merges two real
     * businesses — and the cost of a missing word is only that a true
     * match arrives as "needs review" for a person to confirm.
     *
     * @var list<string>
     */
    private const NOISE = [
        'resort', 'resorts', 'beach', 'the', 'and',
        'inc', 'incorporated', 'corp', 'corporation', 'co',
        'hotel', 'lodge', 'inn', 'house', 'homestay',
    ];

    /**
     * A resort or business name reduced to its identifying words.
     *
     * "ABC Beach Resort, Inc." and "abc resort" both come out as "abc".
     */
    public static function name(?string $value): string
    {
        $words = self::words($value);

        $kept = array_values(array_filter(
            $words,
            fn (string $word): bool => ! in_array($word, self::NOISE, true),
        ));

        /*
         * If a name is NOTHING but noise words — somebody's resort really
         * is called "The Beach House" — keep the words rather than
         * returning an empty key. An empty key would match every other
         * empty key, which is the one outcome worse than not matching.
         */
        return implode(' ', $kept !== [] ? $kept : $words);
    }

    /**
     * A person's name, for comparing an owner against an owner.
     *
     * No noise list: every word in a person's name identifies them. Only
     * the punctuation, case and spacing are levelled, so "Juan Dela Cruz",
     * "juan dela cruz" and "Juan  Dela  Cruz" agree. Middle initials and
     * reversed "Surname, First" orders deliberately do NOT agree — this
     * value only ever confirms a match the name already suggested, so a
     * miss here costs a review, while a false agreement costs the office
     * a wrong answer it has no reason to doubt.
     */
    public static function person(?string $value): string
    {
        return implode(' ', self::words($value));
    }

    /**
     * A barangay name. "Brgy. Tambobong" and "tambobong" agree.
     */
    public static function barangay(?string $value): string
    {
        $words = array_values(array_filter(
            self::words($value),
            fn (string $word): bool => ! in_array($word, ['brgy', 'barangay', 'bgy'], true),
        ));

        return implode(' ', $words);
    }

    /**
     * An accreditation number, levelled so that "ACC-001", "acc 001" and
     * "ACC001" are one number.
     *
     * Leading zeros are kept: ACC-001 and ACC-01 are plausibly two
     * different certificates and it is not this code's place to decide
     * they are the same.
     */
    public static function accreditationNumber(?string $value): string
    {
        $value = Str::of((string) $value)->lower()->ascii();

        return (string) $value->replaceMatches('/[^a-z0-9]+/', '');
    }

    /**
     * Lower-cased, accent-stripped words with punctuation removed.
     *
     * ascii() matters locally: "Osmeña" is typed both with and without
     * the tilde, and those must not be two different barangays.
     *
     * @return list<string>
     */
    private static function words(?string $value): array
    {
        $cleaned = Str::of((string) $value)
            ->ascii()
            ->lower()
            // Apostrophes close up ("sam's" -> "sams"); everything else
            // becomes a gap, so "abc-beach" is two words and not one.
            ->replaceMatches("/['\x{2019}]/u", '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();

        return $cleaned->isEmpty() ? [] : explode(' ', (string) $cleaned);
    }
}
