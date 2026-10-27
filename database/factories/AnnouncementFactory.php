<?php

namespace Database\Factories;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a DRAFT announcement.
 *
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->unique()->sentence(6), '.'),
            'excerpt' => fake()->sentence(14),
            'body' => fake()->paragraphs(3, true),
            'status' => AnnouncementStatus::Draft,
            'is_featured' => false,
            'published_at' => null,
        ];
    }

    public function published(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => AnnouncementStatus::Published,
            'published_at' => $at ?? now()->subDay(),
        ]);
    }
}
