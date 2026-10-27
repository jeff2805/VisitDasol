<?php

namespace App\Policies;

use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\User;

/**
 * Who may name the collection points, and who may take money at them.
 *
 * Two different authorities, deliberately not the same:
 *
 *   NAMING a checkpoint is the tourism office's. Designating where a
 *   municipality collects is a municipal act.
 *
 *   COLLECTING at one comes from an assignment ROW, not a role. This
 *   system has four roles and none of them is "collector"; inventing a
 *   fifth would invent a municipal post that may not exist. The office
 *   names a person AND a gate, and that pairing is the permission —
 *   revocable one gate at a time, because somebody trusted at Tambobong
 *   is not thereby trusted everywhere in Dasol.
 */
class EnvironmentalFeeCheckpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    public function update(User $user, EnvironmentalFeeCheckpoint $checkpoint): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /** Appointing and withdrawing collectors. */
    public function assignCollectors(User $user, EnvironmentalFeeCheckpoint $checkpoint): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /**
     * Whether this person may take money at THIS gate.
     *
     * The office can too: it appoints the collectors and has to be able
     * to stand in at a gate and to put right what happens there.
     */
    public function collectAt(User $user, EnvironmentalFeeCheckpoint $checkpoint): bool
    {
        if (! $user->isActive() || ! $checkpoint->active) {
            return false;
        }

        return $checkpoint->isCollectedBy($user) || $user->isSuperAdmin();
    }
}
