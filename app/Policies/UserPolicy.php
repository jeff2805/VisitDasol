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
