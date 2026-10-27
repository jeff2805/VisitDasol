<?php

namespace App\Policies;

use App\Enums\DestinationStatus;
use App\Models\Destination;
use App\Models\User;

/**
 * Who may write a destination, and who may publish one.
 *
 * Those are deliberately two different people. A barangay official may write
 * up the places in their own barangay, because they are the ones who know
 * them. Only the Municipal Tourism Office may publish, because a municipal
 * site that lets anyone put a claim about a real place in front of visitors
 * is not a municipal site any more.
 *
 * An official is confined to their own barangay in both directions: they
 * cannot write a spot into someone else's barangay, and they cannot open
 * someone else's submission.
 */
class DestinationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isBarangayOfficial();
    }

    public function view(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin() || $this->isTheirOwnSubmission($user, $destination);
    }

    public function create(User $user): bool
    {
        // An official with no barangay recorded has nothing to write about,
        // and must not be able to pick one on the form.
        return $user->isSuperAdmin()
            || ($user->isBarangayOfficial() && filled($user->barangay));
    }

    /**
     * The office may edit anything, at any time.
     *
     * A barangay may edit their own submission only while it is still with
     * them — waiting, or sent back for correction. Once it is published, the
     * text on the public site is the office's responsibility, and a quiet
     * edit afterwards would be a way around the review.
     */
    public function update(User $user, Destination $destination): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->isTheirOwnSubmission($user, $destination)
            && $destination->status->isWithTheBarangay();
    }

    public function manageImages(User $user, Destination $destination): bool
    {
        return $this->update($user, $destination);
    }

    /** Sending a corrected spot back to the office. */
    public function resubmit(User $user, Destination $destination): bool
    {
        return $this->isTheirOwnSubmission($user, $destination)
            && $destination->status === DestinationStatus::Rejected;
    }

    /** Approving or sending back. The office alone. */
    public function review(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin() && $destination->status === DestinationStatus::Pending;
    }

    public function delete(User $user, Destination $destination): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // A barangay may withdraw its own submission while it is still theirs.
        return $this->isTheirOwnSubmission($user, $destination)
            && $destination->status->isWithTheBarangay();
    }

    /** Only a published spot can be highlighted on the home page. */
    public function feature(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin() && $destination->status === DestinationStatus::Active;
    }

    private function isTheirOwnSubmission(User $user, Destination $destination): bool
    {
        return $user->isBarangayOfficial()
            && (int) $destination->created_by === (int) $user->id
            // Belt and braces: an official moved to another barangay stops
            // being able to touch what they wrote for the previous one.
            && $destination->barangay === $user->barangay;
    }
}
