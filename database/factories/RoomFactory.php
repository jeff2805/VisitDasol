<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Resort;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an ACTIVE room type in a new ACTIVE resort.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(RoomType::cases());

        return [
            'resort_id' => Resort::factory()->active(),
            'name' => $type->label().' Room '.fake()->unique()->numberBetween(100, 99999),
            'room_type' => $type,
            'description' => fake()->sentence(12),
            'capacity' => $capacity = fake()->numberBetween(2, 6),
            'bed_count' => fake()->numberBetween(1, $capacity),
            'price_per_night' => fake()->randomElement([1500, 1800, 2500, 3500, 4500, 6000]),
            'total_rooms' => fake()->numberBetween(1, 6),
            'status' => RoomStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RoomStatus::Inactive]);
    }

    /** A room that takes something off a child's share of the bill. */
    public function childDiscount(int $percent = 50, int $minAge = 0, int $maxAge = 12): static
    {
        return $this->state(fn () => [
            'child_discount_enabled' => true,
            'child_discount_percent' => $percent,
            'child_discount_min_age' => $minAge,
            'child_discount_max_age' => $maxAge,
        ]);
    }

    /** A room that takes something off a PWD guest's share of the bill. */
    public function pwdDiscount(int $percent = 20): static
    {
        return $this->state(fn () => [
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => $percent,
        ]);
    }
}
