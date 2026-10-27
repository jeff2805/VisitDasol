<?php

namespace Tests\Feature\Database;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Date-overlap and inventory-holding rules from Phase 0, sections 26 and 27.
 * Existing stay: October 10 to October 13 (nights of the 10th, 11th, 12th).
 */
class BookingScopesTest extends TestCase
{
    use RefreshDatabase;

    public static function overlapCases(): array
    {
        return [
            'inside (Oct 11-12)' => ['2030-10-11', '2030-10-12', true],
            'starts before (Oct 9-11)' => ['2030-10-09', '2030-10-11', true],
            'ends after (Oct 12-15)' => ['2030-10-12', '2030-10-15', true],
            'encloses (Oct 8-16)' => ['2030-10-08', '2030-10-16', true],
            'same dates (Oct 10-13)' => ['2030-10-10', '2030-10-13', true],
            'arrives on checkout day (Oct 13-15)' => ['2030-10-13', '2030-10-15', false],
            'leaves on check-in day (Oct 7-10)' => ['2030-10-07', '2030-10-10', false],
            'far later (Oct 20-22)' => ['2030-10-20', '2030-10-22', false],
        ];
    }

    #[DataProvider('overlapCases')]
    public function test_overlap_detection(string $checkIn, string $checkOut, bool $overlaps): void
    {
        $booking = Booking::factory()->forStay('2030-10-10', '2030-10-13')->confirmed()->create();

        $this->assertSame(
            $overlaps,
            Booking::query()->whereKey($booking->id)->overlapping($checkIn, $checkOut)->exists()
        );
    }

    public function test_which_statuses_hold_inventory(): void
    {
        $this->travelTo('2030-10-01 09:00:00');
        $room = Room::factory()->create();

        $stay = fn () => Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13');

        $confirmed = $stay()->confirmed()->create();
        $pending = $stay()->create();
        $completed = $stay()->completed()->create();
        $cancelled = $stay()->cancelled()->create();
        $rejected = $stay()->rejected()->create();

        $blocking = Booking::query()->blocking()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$confirmed->id, $pending->id, $completed->id], $blocking);
        $this->assertNotContains($cancelled->id, $blocking);
        $this->assertNotContains($rejected->id, $blocking);
    }

    public function test_pending_bookings_stop_holding_inventory_after_check_in_date_passes(): void
    {
        $this->travelTo('2030-10-01 09:00:00');
        $pending = Booking::factory()->forStay('2030-10-10', '2030-10-13')->create();
        $confirmed = Booking::factory()->forStay('2030-10-10', '2030-10-13')->confirmed()->create();

        $this->travelTo('2030-10-10 23:00:00');
        $this->assertContains($pending->id, Booking::query()->blocking()->pluck('id')->all(), 'Still blocking on the check-in day.');

        $this->travelTo('2030-10-11 08:00:00');
        $blocking = Booking::query()->blocking()->pluck('id')->all();
        $this->assertNotContains($pending->id, $blocking, 'A stale pending booking must release the room.');
        $this->assertContains($confirmed->id, $blocking);
    }

    public function test_owner_and_tourist_scopes_only_return_their_own_bookings(): void
    {
        $mine = Booking::factory()->create();
        $theirs = Booking::factory()->create();

        $owner = $mine->resort->owner;
        $tourist = $mine->tourist;

        $this->assertSame([$mine->id], Booking::forOwner($owner)->pluck('id')->all());
        $this->assertSame([$mine->id], Booking::forTourist($tourist)->pluck('id')->all());
        $this->assertNotContains($theirs->id, Booking::forOwner($owner)->pluck('id')->all());
    }

    public function test_status_scope(): void
    {
        Booking::factory()->create();
        Booking::factory()->confirmed()->create();

        $this->assertSame(1, Booking::withStatus(BookingStatus::Confirmed)->count());
        $this->assertSame(1, Booking::withStatus(BookingStatus::Pending)->count());
    }
}
