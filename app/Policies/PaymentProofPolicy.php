<?php

namespace App\Policies;

use App\Models\PaymentProof;
use App\Models\User;

class PaymentProofPolicy
{
    public function view(User $user, PaymentProof $proof): bool
    {
        return $this->ownsResort($user, $proof);
    }

    public function approve(User $user, PaymentProof $proof): bool
    {
        return $this->ownsResort($user, $proof);
    }

    public function reject(User $user, PaymentProof $proof): bool
    {
        return $this->ownsResort($user, $proof);
    }

    public function image(User $user, PaymentProof $proof): bool
    {
        return $this->ownsResort($user, $proof);
    }

    private function ownsResort(User $user, PaymentProof $proof): bool
    {
        return $user->isResortOwner()
            && $user->resorts()->whereKey($proof->payment->booking->resort_id)->exists();
    }
}
