<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Public booking references: VD-20261010-A8F2K
 *
 * The date part is the guest's CHECK-IN date, which is what a resort's
 * front desk looks for. The database id is never shown to guests, and the
 * random part uses an alphabet without 0/O and 1/I, so references are easy
 * to read aloud and to write down.
 */
class BookingReferenceGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LENGTH = 5;

    private const ATTEMPTS = 10;

    public function generate(?CarbonInterface $checkIn = null): string
    {
        $date = $checkIn ?? now();
        $prefix = config('visitdasol.booking.reference_prefix', 'VD').'-'.$date->format('Ymd').'-';

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $reference = $prefix.$this->randomPart();

            if (! Booking::query()->where('booking_reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not generate a unique booking reference.');
    }

    private function randomPart(): string
    {
        $characters = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < self::LENGTH; $position++) {
            $characters .= self::ALPHABET[random_int(0, $max)];
        }

        return $characters;
    }
}
