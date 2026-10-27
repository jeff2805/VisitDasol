<?php

namespace Database\Factories;

use App\Enums\DestinationStatus;
use App\Models\Destination;
use App\Models\DestinationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an ACTIVE destination near the Dasol municipal center.
 *
 * @extends Factory<Destination>
 */
class DestinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => DestinationCategory::factory(),
            'name' => ucwords(fake()->unique()->words(3, true)),
            'description' => fake()->paragraphs(2, true),
            'address' => 'Sitio '.fake()->lastName(),
            'barangay' => fake()->randomElement(config('visitdasol.barangays')),
            'latitude' => fake()->randomFloat(7, 15.965, 16.015),
            'longitude' => fake()->randomFloat(7, 119.855, 119.905),
            'status' => DestinationStatus::Active,
            'is_featured' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => DestinationStatus::Inactive]);
    }

    /** Sent in by a barangay and not yet read by the office. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => DestinationStatus::Pending,
            'submitted_at' => now(),
        ]);
    }

    /** Sent back to the barangay with a reason. */
    public function sentBack(string $remarks = 'Please add how to get there from the highway.'): static
    {
        return $this->state(fn () => [
            'status' => DestinationStatus::Rejected,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'review_remarks' => $remarks,
        ]);
    }

    /** Written by this account, which is what ties it to a barangay official. */
    public function writtenBy(\App\Models\User $user): static
    {
        return $this->state(fn () => [
            'created_by' => $user->id,
            'barangay' => $user->barangay ?? fake()->randomElement(config('visitdasol.barangays')),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true]);
    }
}
