<?php

namespace App\Policies;

use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollection;
use App\Models\User;

/**
 * Who may record, read and cancel a collection.
 */
class EnvironmentalFeeCollectionPolicy
{
    /**
     * Whether to show this person the collection screen at all.
     *
     * Deliberately NOT "has an assignment". Somebody who works a gate
     * but has not been added yet needs to be told that, and a 403 tells
     * them only that something is broken. The screen explains; the
     * checks below decide.
     */
    public function viewAny(User $user): bool
    {
        /*
         * A cashier is deliberately absent. They count what the gates
         * hand in; they do not stand at one, and an account that could
         * both write receipts and count them is the separation this
         * module exists to keep.
         */
        return $user->isActive()
            && ($user->isSuperAdmin()
                || $user->isFeeCollector()
                || $user->isBarangayOfficial());
    }

    /**
     * Reaching the form is not permission to use it. Which gate, and
     * whether this person may work it, is settled by collectAt() on
     * EnvironmentalFeeCheckpointPolicy and again by the service.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * A collector may read their own receipts. The office may read them
     * all, because reconciling what was taken across the municipality is
     * the whole reason the office is here.
     */
    public function view(User $user, EnvironmentalFeeCollection $collection): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isSuperAdmin()
            || (int) $collection->collector_id === (int) $user->id;
    }

    /**
     * Cancelling is the office's alone.
     *
     * A collector able to void their own receipts could take money at a
     * gate and then erase the only record that they did. The separation
     * between taking money and cancelling a record of it is what makes
     * the trail worth keeping.
     */
    public function void(User $user, EnvironmentalFeeCollection $collection): bool
    {
        return $user->isSuperAdmin()
            && $user->isActive()
            && $collection->isVoidable();
    }

    /**
     * A correction replaces a receipt with a new one, so it is both a
     * cancellation and a collection. The office does it.
     */
    public function correct(User $user, EnvironmentalFeeCollection $collection): bool
    {
        return $this->void($user, $collection);
    }
}
