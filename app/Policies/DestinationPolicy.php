<?php

namespace App\Policies;

use App\Enums\DestinationStatus;
use App\Models\Destination;
use App\Models\User;

/**
 * Who may write and publish a destination: the Municipal Tourism Office,
 * and nobody else.
 *
 * It used to be two different people. A barangay official wrote up the
 * places in their own barangay and the office published them, because a
 * municipal site that lets anyone put a claim about a real place in
 * front of visitors is not a municipal site any more. That role has
 * been removed, so only half of the rule is left — the half that
 * matters: the office is the publisher.
 */
class DestinationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageImages(User $user, Destination $destination): bool
    {
        return $this->update($user, $destination);
    }

    public function delete(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin();
    }

    /** Only a published spot can be highlighted on the home page. */
    public function feature(User $user, Destination $destination): bool
    {
        return $user->isSuperAdmin() && $destination->status === DestinationStatus::Active;
    }
}
