<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test and demo users. Defaults to an ACTIVE TOURIST.
 *
 *   User::factory()->create()                          active tourist
 *   User::factory()->resortOwner()->create()           active owner + profile
 *   User::factory()->resortOwner(UserStatus::Pending)  owner application
 *   User::factory()->superAdmin()->create()            MTO account
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Tourist,
            'status' => UserStatus::Active,
            'home_city' => fake()->city(),
            'home_country' => 'Philippines',
            'last_login_at' => null,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function tourist(): static
    {
        return $this->state(fn () => ['role' => UserRole::Tourist]);
    }

    /**
     * A resort owner with an owner profile (created after the user).
     */
    public function resortOwner(UserStatus $status = UserStatus::Active): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ResortOwner,
            'status' => $status,
            'home_city' => null,
            'home_country' => null,
        ])->afterCreating(function (User $user): void {
            if (! $user->ownerProfile()->exists()) {
                OwnerProfile::factory()->for($user)->create();
            }
        });
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
            'home_city' => null,
            'home_country' => null,
        ]);
    }

    public function withStatus(UserStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function inactive(): static
    {
        return $this->withStatus(UserStatus::Inactive);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
