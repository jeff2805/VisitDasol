<?php

namespace Tests\Feature\Database;

use App\Models\Booking;
use App\Models\Room;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-night counting rule from Phase 0, section 27.
 */
class AvailabilityCalculationTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = app(AvailabilityService::class);
    }

    public function test_bookings_that_do_not_overlap_each_other_do_not_stack_up(): void
    {
        $room = Room::factory()->create(['total_rooms' => 2]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-12')->confirmed()->create();
        Booking::factory()->for($room)->forStay('2030-10-12', '2030-10-14')->create();

        // Adding the two bookings together would wrongly say 2 units are in use.
        $this->assertSame(1, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-14'));
    }

    public function test_bookings_that_do_overlap_each_other_are_counted_together(): void
    {
        $room = Room::factory()->create(['total_rooms' => 2]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-12')->confirmed()->create();
        Booking::factory()->for($room)->forStay('2030-10-11', '2030-10-13')->confirmed()->create();

        $this->assertSame(2, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-13'));
    }

    public function test_quantities_within_one_booking_count(): void
    {
        $room = Room::factory()->create(['total_rooms' => 5]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-12', 3)->confirmed()->create();

        $this->assertSame(3, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-12'));
    }

    public function test_cancelled_and_rejected_stays_free_the_room(): void
    {
        $room = Room::factory()->create(['total_rooms' => 1]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13')->cancelled()->create();
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13')->rejected()->create();

        $this->assertSame(0, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-13'));
    }

    public function test_the_checkout_day_is_free_for_the_next_guest(): void
    {
        $room = Room::factory()->create(['total_rooms' => 1]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13')->confirmed()->create();

        $this->assertSame(0, $this->availability->peakUnitsInUse($room, '2030-10-13', '2030-10-15'));
        $this->assertSame(1, $this->availability->peakUnitsInUse($room, '2030-10-12', '2030-10-14'));
    }

    public function test_one_booking_can_be_left_out_of_the_count(): void
    {
        $room = Room::factory()->create(['total_rooms' => 1]);
        $booking = Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13')->confirmed()->create();

        $this->assertSame(1, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-13'));
        $this->assertSame(0, $this->availability->peakUnitsInUse($room, '2030-10-10', '2030-10-13', $booking->id));
    }

    public function test_only_upcoming_nights_count_towards_committed_inventory(): void
    {
        $room = Room::factory()->create(['total_rooms' => 3]);
        Booking::factory()->for($room)->forStay('2030-09-01', '2030-09-05', 3)->completed()->create();
        Booking::factory()->for($room)->forStay('2030-10-20', '2030-10-22', 1)->confirmed()->create();

        $this->travelTo('2030-10-01 08:00:00');

        $this->assertSame(1, $this->availability->peakUnitsCommittedAhead($room));
    }

    public function test_the_fast_calculation_matches_counting_night_by_night(): void
    {
        // Phase 20 replaced a night-by-night loop with a faster sweep. This
        // checks the sweep against the plain method on random stays: many
        // overlaps, same-day turnovers, multi-room bookings, cancellations.
        mt_srand(2030);
        $room = Room::factory()->create(['total_rooms' => 50]);
        $statuses = ['confirmed', 'completed', 'pending', 'cancelled', 'rejected'];

        for ($i = 0; $i < 150; $i++) {
            $in = CarbonImmutable::parse('2030-07-01')->addDays(mt_rand(0, 60));

            Booking::factory()->for($room)
                ->forStay($in->toDateString(), $in->addDays(mt_rand(1, 6))->toDateString(), mt_rand(1, 3))
                ->create(['status' => $statuses[mt_rand(0, 4)]]);
        }

        $this->travelTo('2030-06-01 09:00:00');
        $blocking = $room->bookings()->blocking()->get();

        for ($trial = 0; $trial < 40; $trial++) {
            $from = CarbonImmutable::parse('2030-06-25')->addDays(mt_rand(0, 70));
            $until = $from->addDays(mt_rand(1, 20));

            $expected = 0;
            for ($night = $from; $night->lt($until); $night = $night->addDay()) {
                $expected = max($expected, (int) $blocking
                    ->filter(fn ($b) => $b->check_in_date->lte($night) && $b->check_out_date->gt($night))
                    ->sum('rooms_booked'));
            }

            $this->assertSame($expected, $this->availability->peakUnitsInUse($room, $from, $until),
                "Mismatch for {$from->toDateString()} to {$until->toDateString()}.");
        }

        // And the batched owner-list version agrees with the single-room one.
        $this->assertSame(
            $this->availability->peakUnitsCommittedAhead($room),
            $this->availability->peakCommittedAheadForRooms([$room])[$room->id]
        );
    }

    public function test_no_bookings_means_nothing_committed(): void
    {
        $this->assertSame(0, $this->availability->peakUnitsCommittedAhead(Room::factory()->create()));
    }
}
