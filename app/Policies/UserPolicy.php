<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Only the Municipal Tourism Office manages accounts, and even the office
 * may not act on itself or on another office account.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin();
    }

    /**
     * Creating an account for a barangay official.
     *
     * The office only. There is no public form for this: an account that can
     * put claims about a real barangay in front of visitors is given out, not
     * signed up for.
     */
    public function createBarangayOfficial(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    /* Environmental fee staff ------------------------------------------- */

    /**
     * Reading the staff list.
     *
     * The office alone. A collector has no business knowing who else
     * works the gates, and a cashier counts money rather than managing
     * the people who bring it.
     */
    public function viewStaff(User $actor): bool
    {
        return $actor->isSuperAdmin() && $actor->isActive();
    }

    /**
     * Creating an account for environmental fee staff.
     *
     * THE OFFICE ONLY, AND NOTHING ELSE COMES CLOSE. An account that
     * may take municipal money at a barrier is handed out by the
     * municipality; it is never signed up for, and it is never created
     * by somebody who already holds one.
     *
     * A collector who could create accounts could create a second
     * collector, assign it to their own gate, and write receipts under
     * a name the office has never heard of.
     */
    public function createStaff(User $actor): bool
    {
        return $actor->isSuperAdmin() && $actor->isActive();
    }

    /**
     * Changing a staff member's post, employee number or checkpoints.
     *
     * The office, and never on their own account. Being able to change
     * your own role is the whole of privilege escalation in one step:
     * a cashier who could edit themselves would simply become an
     * administrator.
     *
     * The office cannot demote itself either, which also keeps it from
     * locking the municipality out of its own site by accident.
     */
    public function updateStaff(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && $actor->isActive()
            && ! $actor->is($target)
            && $target->isEnvironmentalFeeStaff();
    }

    /**
     * Sending the link that lets somebody set their first password.
     *
     * Same authority as creating them. A deactivated account gets no
     * invitation: the link would work, and an account the office has
     * switched off must not be handed a way back in.
     */
    public function inviteStaff(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && $actor->isActive()
            && $target->isEnvironmentalFeeStaff()
            && $target->isActive();
    }

    /** Approve, reject, or reopen an owner application. */
    public function review(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && $target->isResortOwner()
            && ! $actor->is($target);
    }

    public function approve(User $actor, User $target): bool
    {
        return $this->review($actor, $target) && $target->status === UserStatus::Pending;
    }

    public function reject(User $actor, User $target): bool
    {
        return $this->approve($actor, $target);
    }

    public function reopen(User $actor, User $target): bool
    {
        return $this->review($actor, $target) && $target->status === UserStatus::Rejected;
    }

    public function activate(User $actor, User $target): bool
    {
        return $this->manageAccount($actor, $target) && $target->status === UserStatus::Inactive;
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->manageAccount($actor, $target) && $target->status === UserStatus::Active;
    }

    /**
     * The office never locks itself out: no acting on your own account,
     * and no acting on another office account.
     */
    private function manageAccount(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $actor->is($target)
            && ! $target->isSuperAdmin();
    }
}
