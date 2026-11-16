<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Where a walk-in record has got to with the Municipal Tourism Office.
 * Stored in bookings.submission_status, and null for an online booking.
 *
 *   draft ──submit──► pending_review ──accept──► accepted  (final)
 *                           │
 *                        return (remarks required)
 *                           ▼
 *                       returned ──resubmit──► resubmitted ──accept──► accepted
 *                                                     │
 *                                                  return
 *                                                     ▼
 *                                                 returned
 *
 * THIS HAS NOTHING TO DO WITH WHETHER THE ROOM IS HELD. A walk-in holds its
 * room from the moment it is saved, while it is still a draft, and keeps
 * holding it when the office sends it back for correction — the guest is
 * standing at the resort either way. bookings.status is what inventory
 * follows; this is only about the monthly return to the Department.
 *
 * pending_review and resubmitted are both "waiting on the office" and are
 * kept apart on purpose, so a reviewer can see at a glance which records
 * they have already sent back once.
 */
enum WalkInSubmissionStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Returned = 'returned';
    case Resubmitted = 'resubmitted';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Returned => 'Returned for correction',
            self::Resubmitted => 'Resubmitted',
            self::Accepted => 'Accepted',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Saved but not yet sent to the tourism office.',
            self::PendingReview => 'Waiting for the tourism office to review it.',
            self::Returned => 'The tourism office asked for corrections.',
            self::Resubmitted => 'Corrected and sent back for review.',
            self::Accepted => 'Accepted by the tourism office.',
        };
    }

    /**
     * Whether the resort may still change the record.
     *
     * A record sitting with the office must not move under the reviewer, and
     * an accepted one has been counted in a return that may already have been
     * filed. Both are refused in the policy AND again in the service under a
     * row lock, because a page left open is not a promise.
     */
    public function isEditableByOwner(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /** Whether the resort may send this to the office now. */
    public function isSubmittable(): bool
    {
        return $this->isEditableByOwner();
    }

    /** Whether the office has something to decide on. */
    public function isAwaitingReview(): bool
    {
        return $this === self::PendingReview || $this === self::Resubmitted;
    }

    /** Whether this record counts towards the Department of Tourism return. */
    public function isReportable(): bool
    {
        return $this === self::Accepted;
    }

    /** Whether the record is finished with and may no longer move. */
    public function isFinal(): bool
    {
        return $this === self::Accepted;
    }
}
