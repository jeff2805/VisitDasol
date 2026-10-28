<?php

namespace App\Policies;

use App\Models\EnvironmentalFeeRemittance;
use App\Models\User;

/**
 * Who may hand money over, and who may count it.
 *
 * THE SEPARATION IS THE POINT. A collector declares; the office counts.
 * Anyone who could do both could hand in a short envelope and then
 * certify it as correct, and the reconciliation would be theatre.
 *
 * Mirrors EnvironmentalFeeCollectionPolicy, which keeps voiding away
 * from the people who take the money for the same reason.
 */
class EnvironmentalFeeRemittancePolicy
{
    /**
     * Whether to show the handover screens at all.
     *
     * Deliberately not "has an assignment": somebody who works a gate
     * but has not been added yet needs to be told so, and a 403 tells
     * them only that something is broken. The screen explains.
     */
    public function viewAny(User $user): bool
    {
        /*
         * A cashier belongs here: counting what the gates turn in is
         * the job. They reach the office screen through their own
         * policy ability below, not this one.
         */
        return $user->isActive()
            && ($user->isSuperAdmin()
                || $user->isFeeCollector()
                || $user->isCashier()
                || $user->isBarangayOfficial());
    }

    /**
     * Reaching the form is not permission to use it. Whether this
     * person may work the gate they picked is settled by
     * EnvironmentalFeeCheckpointPolicy::collectAt() and again inside
     * the service.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * A collector may read their own handovers. The office may read
     * them all, because reconciling across the municipality is the
     * whole reason the office is here.
     */
    public function view(User $user, EnvironmentalFeeRemittance $remittance): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        /*
         * A CASHIER TOO.
         *
         * review() below already lets a cashier count an envelope, and
         * viewAny() already shows them the office list — but this was
         * left at super admin, so a cashier could see a handover in the
         * list, click it, and be refused the one page they have to open
         * to do the counting. Permission to count without permission to
         * read is not a safer rule, only an unusable one.
         *
         * Reading is not signing off. review() still refuses a cashier
         * their own envelope and anything already reviewed.
         */
        return $user->isSuperAdmin()
            || $user->isCashier()
            || (int) $remittance->collector_id === (int) $user->id;
    }

    /**
     * Counting is the office's alone, and never the collector's own.
     *
     * The second condition is the one that matters: a super admin who
     * also happens to have collected at a gate may not sign off their
     * own envelope. Nothing in this system stops one person holding
     * both posts, so the rule is enforced on the row rather than
     * assumed from the role.
     */
    public function review(User $user, EnvironmentalFeeRemittance $remittance): bool
    {
        /*
         * A cashier counts money for a living, which is exactly this.
         * The office may do it too, because somebody has to when the
         * cashier is the person who handed it in.
         */
        return ($user->isSuperAdmin() || $user->isCashier())
            && $user->isActive()
            && ! $remittance->status->isReviewed()
            && (int) $remittance->collector_id !== (int) $user->id;
    }

    /**
     * Handovers are a financial record. Nothing deletes one — a wrong
     * figure is answered with a disputed count and a note, which is
     * what an auditor can actually follow.
     */
    public function delete(User $user, EnvironmentalFeeRemittance $remittance): bool
    {
        return false;
    }
}
