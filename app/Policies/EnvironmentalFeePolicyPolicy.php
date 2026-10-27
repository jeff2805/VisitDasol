<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may configure the municipal environmental fee.
 *
 * The tourism office alone. Switching a municipal collection on, setting
 * its rate and naming its dates are acts of the municipality — a resort
 * owner may never do any of them. This fee is not theirs, and a business
 * with an interest in visitor numbers must not be able to set the charge
 * on those visitors.
 *
 * Found by Laravel's own naming: App\Models\EnvironmentalFeePolicy ->
 * App\Policies\EnvironmentalFeePolicyPolicy. The project registers no
 * policies by hand, so the name is the wiring.
 */
class EnvironmentalFeePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /** Setting the rate, the dates, and whether collection happens at all. */
    public function configure(User $user): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /**
     * Changing when a fee stops applying, and nothing else.
     *
     * Deliberately narrower than configure(). A period can be extended
     * or left open; THE RATE CAN NEVER BE EDITED. Once receipts have
     * been written against a policy, changing its amount would rewrite
     * what visitors were charged on days already past — the one thing
     * an audit trail exists to prevent. A new rate is a new policy.
     */
    public function extend(User $user, \App\Models\EnvironmentalFeePolicy $policy): bool
    {
        return $user->isSuperAdmin() && $user->isActive();
    }

    /**
     * The municipality's takings are the municipality's to read. A
     * collector sees their own receipts elsewhere; they have no business
     * reading the whole of Dasol's.
     */
    public function viewReports(User $user): bool
    {
        /*
         * A cashier reads these. Reconciling what the gates turned in
         * against what the receipts say is the job, and it cannot be
         * done without the municipal totals.
         *
         * Reading them is all it grants. Setting the rate, switching
         * collection on and creating staff all stay with configure()
         * and UserPolicy, which a cashier fails.
         */
        return ($user->isSuperAdmin() || $user->isCashier()) && $user->isActive();
    }
}
