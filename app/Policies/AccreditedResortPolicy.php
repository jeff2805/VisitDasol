<?php

namespace App\Policies;

use App\Models\User;

/**
 * The official accreditation list belongs to the Municipal Tourism
 * Office and to nobody else.
 *
 * A resort owner who could write here could add their own business to
 * the register they are about to be judged against, and the review
 * screen would show the office a green badge the office never issued.
 * That is the whole reason this file exists: the routes are already
 * behind the office's role, and this is the second lock, so a mistake
 * in routing alone cannot open it.
 */
class AccreditedResortPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
