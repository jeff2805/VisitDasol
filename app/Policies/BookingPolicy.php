<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who may see or act on a reservation.
 * Phase 12 and 13 add confirm, reject, complete, and cancel.
 */
class BookingPolicy
{
    /** Only tourists make reservations. */
    public function create(User $user): bool
    {
        return $user->isTourist() && $user->isActive();
    }

    public function viewAny(User $user): bool
    {
        return $user->isTourist() || $user->isResortOwner() || $user->isSuperAdmin();
    }

    /**
     * The guest who booked, the owner of the resort, and the office.
     * Nobody else, even with the booking reference.
     */
    public function view(User $user, Booking $booking): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isTourist()) {
            return (int) $booking->user_id === (int) $user->id;
        }

        return $user->isResortOwner()
            && $user->resorts()->whereKey($booking->resort_id)->exists();
    }

    /* Guest decisions ------------------------------------------------------ */

    /**
     * A guest may call off their own reservation while it is still waiting
     * or confirmed, up to the cut-off before arrival
     * (config visitdasol.booking.tourist_cancel_cutoff_days, 0 by default,
     * which means up to the day before they arrive).
     */
    public function cancelAsTourist(User $user, Booking $booking): bool
    {
        if (! $user->isTourist() || (int) $booking->user_id !== (int) $user->id) {
            return false;
        }

        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            return false;
        }

        $cutoff = (int) config('visitdasol.booking.tourist_cancel_cutoff_days', 0);

        return $booking->check_in_date->gt(CarbonImmutable::today()->addDays($cutoff));
    }

    /* Owner decisions ----------------------------------------------------- */

    /** Accept a request. Not possible once the arrival date has passed. */
    public function confirm(User $user, Booking $booking): bool
    {
        return $this->ownsResort($user, $booking)
            && $booking->status === BookingStatus::Pending
            && $booking->check_in_date->gte(CarbonImmutable::today());
    }

    /**
     * Answer what the guest asked for besides the room.
     *
     * Deliberately NOT tied to the booking still being pending. A
     * guest may ask for a banana boat on a stay the resort confirmed
     * last week, and the weather on the day is the thing that decides
     * — so the owner can answer for as long as the stay is live.
     *
     * A cancelled or rejected booking is finished with, and so are its
     * requests.
     */
    public function manageExtras(User $user, Booking $booking): bool
    {
        return $this->ownsResort($user, $booking)
            && in_array($booking->status, [
                BookingStatus::Pending,
                BookingStatus::Confirmed,
            ], true);
    }

    /** Decline a request. Possible for as long as it is still waiting. */
    public function reject(User $user, Booking $booking): bool
    {
        return $this->ownsResort($user, $booking) && $booking->status === BookingStatus::Pending;
    }

    /** Mark a finished stay as completed, once the guest has checked out. */
    public function complete(User $user, Booking $booking): bool
    {
        return $this->ownsResort($user, $booking)
            && $booking->status === BookingStatus::Confirmed
            && $booking->check_out_date->lte(CarbonImmutable::today());
    }

    /** Call off a confirmed stay before the guest arrives (storm, repairs). */
    public function cancelAsOwner(User $user, Booking $booking): bool
    {
        return $this->ownsResort($user, $booking)
            && $booking->status === BookingStatus::Confirmed
            && $booking->check_in_date->gt(CarbonImmutable::today());
    }

    /**
     * Checked with a query rather than by reading $booking->resort, so a
     * list of reservations does not need the relation loaded.
     */
    private function ownsResort(User $user, Booking $booking): bool
    {
        return $user->isResortOwner()
            && $user->resorts()->whereKey($booking->resort_id)->exists();
    }
}
