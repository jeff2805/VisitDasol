<?php

namespace App\Policies;

use App\Models\ResortExtra;
use App\Models\User;

/**
 * Who may change what a resort offers besides the room.
 *
 * The resort's own owner, and the office. Checked against the extra's
 * OWN resort rather than the signed-in owner's, so changing an id in
 * the URL reaches somebody else's list and is refused there.
 */
class ResortExtraPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && ($user->isResortOwner() || $user->isSuperAdmin());
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->isResortOwner();
    }

    public function update(User $user, ResortExtra $extra): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $extra->resort !== null && $extra->resort->isOwnedBy($user);
    }

    /**
     * Nothing deletes one. Guests have asked for these by name and
     * their requests carry it; an owner who is finished with something
     * switches it off, which takes it off the booking form and leaves
     * every record that mentions it intact.
     */
    public function delete(User $user, ResortExtra $extra): bool
    {
        return false;
    }
}
