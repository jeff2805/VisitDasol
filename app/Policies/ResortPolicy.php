<?php

namespace App\Policies;

use App\Enums\ResortStatus;
use App\Models\Resort;
use App\Models\User;

/**
 * An owner may only ever touch their own resort, and may never approve it.
 * Approval belongs to the Municipal Tourism Office alone (Phase 7).
 */
class ResortPolicy
{
    /** Listing every resort, whatever its status, is for the office only. */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** One resort per owner account. */
    public function create(User $user): bool
    {
        return $user->isResortOwner()
            && $user->isActive()
            && ! $user->resorts()->exists();
    }

    public function view(User $user, Resort $resort): bool
    {
        return $resort->isOwnedBy($user) || $user->isSuperAdmin();
    }

    public function update(User $user, Resort $resort): bool
    {
        return ($user->isResortOwner() && $resort->isOwnedBy($user)) || $user->isSuperAdmin();
    }

    public function manageImages(User $user, Resort $resort): bool
    {
        return $this->update($user, $resort);
    }

    /**
     * Payment terms are the owner's alone.
     *
     * Deliberately NOT update(), which the office shares: what a resort
     * charges up front is its own commercial decision, and the Municipal
     * Tourism Office has no business setting it. The office's interest
     * is visitor numbers, not a resort's takings.
     */
    public function managePaymentPolicy(User $user, Resort $resort): bool
    {
        return $user->isResortOwner()
            && $user->isActive()
            && $resort->isOwnedBy($user);
    }

    /** Only a rejected resort can be sent back for another review. */
    public function resubmit(User $user, Resort $resort): bool
    {
        return $user->isResortOwner()
            && $resort->isOwnedBy($user)
            && $resort->status === ResortStatus::Rejected;
    }

    /** Approve, reject, activate, deactivate, feature: the office only. */
    public function review(User $user, Resort $resort): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Only a pending listing can be approved, and only when its owner
     * can actually sign in and manage it.
     */
    public function approve(User $user, Resort $resort): bool
    {
        return $this->review($user, $resort)
            && $resort->status === ResortStatus::Pending
            && (bool) $resort->owner?->isActive();
    }

    public function reject(User $user, Resort $resort): bool
    {
        return $this->review($user, $resort) && $resort->status === ResortStatus::Pending;
    }

    public function activate(User $user, Resort $resort): bool
    {
        return $this->review($user, $resort) && $resort->status === ResortStatus::Inactive;
    }

    public function deactivate(User $user, Resort $resort): bool
    {
        return $this->review($user, $resort) && $resort->status === ResortStatus::Active;
    }

    /** Only a live listing can be highlighted on the home page. */
    public function feature(User $user, Resort $resort): bool
    {
        return $this->review($user, $resort) && $resort->status === ResortStatus::Active;
    }
}
