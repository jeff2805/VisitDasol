<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test bookings. Real bookings are created only by BookingService (Phase 11),
 * which also checks availability; this factory does not.
 *
 *   Booking::factory()->for($room)->forStay('2026-10-10', '2026-10-13')->confirmed()->create()
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = CarbonImmutable::today()->addDays(fake()->numberBetween(7, 60));
        $nights = fake()->numberBetween(1, 4);

        return [
            'booking_reference' => 'VD-'.$checkIn->format('Ymd').'-'.fake()->unique()->regexify('[A-HJ-NP-Z2-9]{5}'),
            'user_id' => User::factory(),
            // room_id must come before the closures below, which read it.
            'room_id' => Room::factory(),
            'resort_id' => fn (array $attributes) => Room::query()->findOrFail($attributes['room_id'])->resort_id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkIn->addDays($nights),

            // The resort's own hours, the way BookingService fills them in.
            // A stay with no hour on it is one that cannot be closed.
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',

            'nights' => $nights,

            // A party of adults with nothing taken off, so a booking made
            // here reads the same way a booking made through the form does.
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'guests' => fn (array $attributes) => $attributes['adults'] + $attributes['children'],

            'rooms_booked' => 1,
            'beds' => fn (array $attributes) => Room::query()
                ->findOrFail($attributes['room_id'])
                ->bed_count * $attributes['rooms_booked'],

            'unit_price' => fn (array $attributes) => Room::query()->findOrFail($attributes['room_id'])->price_per_night,
            'subtotal_amount' => fn (array $attributes) => number_format(
                (float) $attributes['unit_price'] * $attributes['nights'] * $attributes['rooms_booked'], 2, '.', ''
            ),
            'total_amount' => fn (array $attributes) => $attributes['subtotal_amount'],

            'contact_name' => fake()->name(),
            'contact_phone' => '09'.fake()->numerify('#########'),
            'status' => BookingStatus::Pending,
        ];
    }

    /**
     * Set both dates and the matching number of nights.
     */
    public function forStay(string $checkIn, string $checkOut, int $roomsBooked = 1): static
    {
        $in = CarbonImmutable::parse($checkIn);
        $out = CarbonImmutable::parse($checkOut);

        return $this->state(fn () => [
            'booking_reference' => 'VD-'.$in->format('Ymd').'-'.fake()->unique()->regexify('[A-HJ-NP-Z2-9]{5}'),
            'check_in_date' => $in,
            'check_out_date' => $out,
            'nights' => (int) $in->diffInDays($out),
            'rooms_booked' => $roomsBooked,
        ]);
    }

    public function withStatus(BookingStatus $status): static
    {
        $timestamps = match ($status) {
            BookingStatus::Pending => [],
            BookingStatus::Confirmed => ['confirmed_at' => now()],
            BookingStatus::Rejected => ['rejected_at' => now()],
            BookingStatus::Cancelled => ['cancelled_at' => now()],
            BookingStatus::Completed => ['confirmed_at' => now(), 'completed_at' => now()],
        };

        return $this->state(fn () => ['status' => $status] + $timestamps);
    }

    public function confirmed(): static
    {
        return $this->withStatus(BookingStatus::Confirmed);
    }

    public function rejected(): static
    {
        return $this->withStatus(BookingStatus::Rejected);
    }

    public function cancelled(): static
    {
        return $this->withStatus(BookingStatus::Cancelled);
    }

    public function completed(): static
    {
        return $this->withStatus(BookingStatus::Completed);
    }
}
