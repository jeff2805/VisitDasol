<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;

/**
 * Who may write, answer, and take down a review.
 *
 * The rule that matters most is the first one: a review must hang from a
 * completed stay that belongs to the person writing it. That is what makes
 * the ratings on this site worth reading, and it cannot be got round by
 * editing a form, because the booking is looked up from the database.
 */
class ReviewPolicy
{
    /**
     * Only the guest who took this booking, only once it is finished, and
     * only if they have not already written one.
     */
    public function create(User $user, Booking $booking): bool
    {
        return $user->isTourist()
            && (int) $booking->user_id === (int) $user->id
            && $booking->status === BookingStatus::Completed
            && ! $booking->review()->exists();
    }

    /** An author may correct their own review; the office may not rewrite it. */
    public function update(User $user, Review $review): bool
    {
        return (int) $review->user_id === (int) $user->id;
    }

    /**
     * The resort being reviewed may answer, once, in public.
     *
     * A hidden review gets no reply: answering something visitors cannot see
     * would only draw attention to it.
     */
    public function reply(User $user, Review $review): bool
    {
        return $user->isResortOwner()
            && $review->resort?->isOwnedBy($user) === true
            && $review->isPublished();
    }

    /** Taking a review down, and putting it back, belongs to the office. */
    public function moderate(User $user, Review $review): bool
    {
        return $user->isSuperAdmin();
    }

    /** Listing every review, hidden ones included. */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
