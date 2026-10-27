<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where a booking party travelled from. Stored in bookings.origin.
 *
 * These buckets are the ones the Department of Tourism's monthly accommodation
 * return asks municipal offices for, which is the form this feeds: Pangasinan,
 * the rest of Region I, Metro Manila, and overseas.
 *
 * Pangasinan is part of the Ilocos Region, so "Ilocos" here means the rest of
 * it — La Union, Ilocos Sur, Ilocos Norte. They are counted apart because the
 * office is asked to report its own province separately from its neighbours.
 *
 * OtherPhilippines exists although the DOT form has no column for it. Without
 * it a guest from Cebu or Davao would belong nowhere, and the columns would
 * quietly stop adding up to the number of guests — a report that does not
 * reconcile is worse than one with an extra column.
 */
enum VisitorOrigin: string
{
    use EnumHelpers;

    case Pangasinan = 'pangasinan';
    case Ilocos = 'ilocos';
    case Ncr = 'ncr';
    case OtherPhilippines = 'other_ph';
    case Foreign = 'foreign';

    public function label(): string
    {
        return match ($this) {
            self::Pangasinan => 'Pangasinan',
            self::Ilocos => 'Ilocos Region (La Union, Ilocos Sur or Norte)',
            self::Ncr => 'Metro Manila (NCR)',
            self::OtherPhilippines => 'Elsewhere in the Philippines',
            self::Foreign => 'Another country',
        };
    }

    /** The short heading the report column uses, as the DOT form writes it. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Pangasinan => 'Pang',
            self::Ilocos => 'Ilocos',
            self::Ncr => 'NCR',
            self::OtherPhilippines => 'Other PH',
            self::Foreign => 'Foreign',
        };
    }
}
