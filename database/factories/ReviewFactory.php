<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A review hangs from a booking, and takes its resort and author from
        // it, so a factory review is never about a stay that did not happen.
        $booking = Booking::factory()->completed();

        return [
            'booking_id' => $booking,
            'resort_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->resort_id,
            'user_id' => fn (array $attributes) => Booking::find($attributes['booking_id'])?->user_id,
            'rating' => fake()->numberBetween(Review::MIN_RATING, Review::MAX_RATING),
            'comment' => fake()->optional(0.8)->paragraph(),
            'status' => ReviewStatus::Published,
        ];
    }

    public function rated(int $stars): static
    {
        return $this->state(fn (): array => ['rating' => $stars]);
    }

    public function hidden(string $reason = 'Not about this resort.'): static
    {
        return $this->state(fn (): array => [
            'status' => ReviewStatus::Hidden,
            'hidden_at' => now(),
            'hidden_reason' => $reason,
        ]);
    }
}
