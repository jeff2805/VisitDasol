<?php

namespace Database\Factories;

use App\Enums\ResortStatus;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a PENDING resort owned by a new active owner.
 * Coordinates fall near the Dasol municipal center.
 *
 * @extends Factory<Resort>
 */
class ResortFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->resortOwner(),
            'name' => ucwords(fake()->unique()->words(2, true)).' Beach Resort',
            'description' => fake()->paragraphs(2, true),
            'address' => 'Sitio '.fake()->lastName(),
            'barangay' => fake()->randomElement(config('visitdasol.barangays')),
            'latitude' => fake()->randomFloat(7, 15.965, 16.015),
            'longitude' => fake()->randomFloat(7, 119.855, 119.905),
            'contact_number' => '09'.fake()->numerify('#########'),
            'email' => fake()->safeEmail(),
            'check_in_time' => '14:00:00',
            'check_out_time' => '12:00:00',
            'status' => ResortStatus::Pending,
            'is_featured' => false,
            'submitted_at' => now(),
        ];
    }

    public function withStatus(ResortStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'reviewed_at' => $status === ResortStatus::Pending ? null : now(),
        ]);
    }

    public function active(): static
    {
        return $this->withStatus(ResortStatus::Active);
    }

    public function rejected(string $remarks = 'Incomplete information.'): static
    {
        return $this->withStatus(ResortStatus::Rejected)->state(fn () => ['review_remarks' => $remarks]);
    }

    public function inactive(): static
    {
        return $this->withStatus(ResortStatus::Inactive);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }
}
