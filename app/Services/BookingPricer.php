<?php

namespace App\Services;

use App\Enums\BookingType;
use App\Models\Room;

/**
 * Works out what a stay costs once the resort's child and PWD
 * discounts are applied.
 *
 * A room is priced by the night, not by the head, so a discount that
 * belongs to one guest is taken against that guest's share of the
 * room bill:
 *
 *     share = subtotal / (adults + children)
 *
 * PWD guests are a subset of the adults and children, never extra
 * people, so a guest who is both a child and a PWD must be discounted
 * ONCE. The form asks for counts and not for who is who, so the PWD
 * count is read against the adults first: that leaves the smallest
 * overlap the stated figures allow, and whoever is left over in both
 * groups is charged at whichever of the two rates is kinder to them.
 *
 * Mirrored in public/js/booking-form.js so the form can show the same
 * figures before the request is sent. Change the two together — the
 * figure here is the one that is charged.
 */
class BookingPricer
{
    public function quote(
        Room $room,
        int $nights,
        int $rooms,
        int $adults,
        int $children,
        int $pwdGuests,
        BookingType $type = BookingType::Overnight,
    ): PriceBreakdown {
        $nights = max(0, $nights);
        $rooms = max(0, $rooms);
        $adults = max(0, $adults);
        $children = max(0, $children);

        $guests = $adults + $children;

        // A PWD count larger than the party is a nonsense the pricing
        // must survive; validation rejects it separately.
        $pwdGuests = max(0, min($pwdGuests, $guests));

        /*
         * A night is sold by the night; a tour is sold by the booking.
         *
         * Multiplying a tour by nights would make it free, because a
         * tour has none — that is what nights = 0 means. So the two are
         * counted differently and the discounts below apply to
         * whichever subtotal comes out.
         */
        $rate = (int) round((float) ($room->priceFor($type) ?? 0) * 100);

        $subtotal = $type->isOvernight()
            ? $rate * $nights * $rooms
            : $rate * $rooms;

        $childPercent = $this->percent(
            (bool) $room->child_discount_enabled,
            $room->child_discount_percent
        );

        $pwdPercent = $this->percent(
            (bool) $room->pwd_discount_enabled,
            $room->pwd_discount_percent
        );

        // PWD guests counted against the adults first.
        $pwdAdults = min($pwdGuests, $adults);
        $pwdChildren = $pwdGuests - $pwdAdults;
        $plainChildren = max(0, $children - $pwdChildren);

        // The guests in both groups are discounted once, at the
        // kinder rate, and counted under that rate only.
        $childrenDiscounted = $plainChildren
            + ($childPercent >= $pwdPercent ? $pwdChildren : 0);

        $pwdDiscounted = $pwdAdults
            + ($pwdPercent > $childPercent ? $pwdChildren : 0);

        $childDiscount = $this->shareOf(
            $subtotal,
            $guests,
            $childrenDiscounted,
            $childPercent
        );

        $pwdDiscount = $this->shareOf(
            $subtotal,
            $guests,
            $pwdDiscounted,
            $pwdPercent
        );

        /*
         * Never give away more than the room costs. Capped in order so
         * the two lines still add up to the total shown beneath them.
         */
        $childDiscount = min($subtotal, $childDiscount);
        $pwdDiscount = min($subtotal - $childDiscount, $pwdDiscount);

        $discountTotal = $childDiscount + $pwdDiscount;

        return new PriceBreakdown(
            subtotal: $this->money($subtotal),
            childDiscount: $this->money($childDiscount),
            pwdDiscount: $this->money($pwdDiscount),
            discountTotal: $this->money($discountTotal),
            total: $this->money($subtotal - $discountTotal),
            childPercent: $childDiscount > 0 ? $childPercent : 0,
            pwdPercent: $pwdDiscount > 0 ? $pwdPercent : 0,
            childrenDiscounted: $childDiscount > 0 ? $childrenDiscounted : 0,
            pwdDiscounted: $pwdDiscount > 0 ? $pwdDiscounted : 0,
        );
    }

    /**
     * A policy only counts when the owner has switched it on and put a
     * usable figure against it.
     */
    private function percent(bool $enabled, int|string|null $percent): int
    {
        if (! $enabled) {
            return 0;
        }

        return max(0, min(100, (int) $percent));
    }

    /**
     * The discounted guests' share of the room bill, in centavos.
     *
     * Divided last and truncated, so a share that does not divide
     * evenly rounds towards the resort rather than away from it, and
     * never by more than a centavo.
     */
    private function shareOf(
        int $subtotal,
        int $guests,
        int $discountedGuests,
        int $percent
    ): int {
        if (
            $subtotal < 1
            || $guests < 1
            || $discountedGuests < 1
            || $percent < 1
        ) {
            return 0;
        }

        return intdiv(
            $subtotal * min($discountedGuests, $guests) * $percent,
            $guests * 100
        );
    }

    private function money(int $centavos): string
    {
        return number_format(max(0, $centavos) / 100, 2, '.', '');
    }
}
