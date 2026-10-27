<?php

namespace App\Policies;

use App\Models\User;

/**
 * The destination category list is managed by the Municipal Tourism Office only.
 * The routes are already limited to that role; this policy is the second
 * lock, so a mistake in the routes alone cannot expose these actions.
 */
class DestinationCategoryPolicy
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
