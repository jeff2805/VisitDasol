<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerProfile>
 */
class OwnerProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A plain owner state (not ->resortOwner()) avoids creating a second profile.
            'user_id' => User::factory()->state([
                'role' => UserRole::ResortOwner,
                'status' => UserStatus::Pending,
            ]),
            'business_name' => fake()->company(),
            'position' => fake()->randomElement(OwnerProfile::POSITIONS),
            'business_permit_no' => 'BP-'.fake()->numerify('####-#####'),
        ];
    }
}
