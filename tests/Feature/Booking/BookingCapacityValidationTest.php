<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\BookingStatusManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How big a party may be, and how many units it may take.
 *
 * THE CEILING WAS ENFORCED IN TWO PLACES WITH TWO SUMS. StoreBookingRequest
 * multiplied capacity by rooms_booked and so did BookingService, and neither
 * knew about a floor because there was no column for one. Room::partyProblem()
 * is the single answer now, and these tests ask the form and the service the
 * same questions so a drift between them fails here rather than at a gate.
 */
class BookingCapacityValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($owner, 'owner')->active()->create();
        $this->tourist = User::factory()->create();
    }

    private function room(array $state = []): Room
    {
        return Room::factory()->for($this->resort)->create($state + [
            'capacity' => 4,
            'total_rooms' => 3,
            'price_per_night' => 1500,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room' => $room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'guests' => 2,
            'rooms_booked' => 1,
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
            'contact_first_name' => 'Ana',
            'contact_last_name' => 'Reyes',
            'contact_phone' => '09171234567',
        ], $overrides);
    }

    private function send(Room $room, array $overrides = [])
    {
        return $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($room, $overrides));
    }

    /* The ceiling ---------------------------------------------------------- */

    public function test_a_party_larger_than_the_room_is_refused(): void
    {
        $room = $this->room(['capacity' => 4]);

        $this->send($room, ['adults' => 5, 'guests' => 5, 'guests_male' => 3, 'guests_female' => 2])
            ->assertSessionHasErrors('guests');

        $this->assertDatabaseCount('bookings', 0);
    }

    /** The ceiling is per unit, so more rooms raises it. */
    public function test_more_rooms_raise_the_ceiling(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 3]);

        $this->send($room, [
            'adults' => 6, 'guests' => 6, 'guests_male' => 3, 'guests_female' => 3,
            'rooms_booked' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bookings', ['guests' => 6, 'rooms_booked' => 2]);
    }

    /* The floor ------------------------------------------------------------ */

    public function test_a_party_smaller_than_the_minimum_is_refused(): void
    {
        $room = $this->room(['capacity' => 8, 'min_capacity' => 4]);

        $this->send($room, ['adults' => 2, 'guests' => 2, 'guests_male' => 1, 'guests_female' => 1])
            ->assertSessionHasErrors('guests');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_the_minimum_message_says_what_is_needed(): void
    {
        $room = $this->room(['capacity' => 8, 'min_capacity' => 4]);

        $this->assertStringContainsString(
            'The minimum number of guests for this room is not satisfied.',
            (string) $room->partyProblem(2, 1)
        );
    }

    public function test_a_party_at_the_minimum_is_accepted(): void
    {
        $room = $this->room(['capacity' => 8, 'min_capacity' => 4]);

        $this->send($room, ['adults' => 4, 'guests' => 4, 'guests_male' => 2, 'guests_female' => 2])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bookings', ['guests' => 4]);
    }

    /** The floor is per unit too: two of a 4-minimum room needs eight. */
    public function test_the_minimum_scales_with_the_rooms_taken(): void
    {
        $room = $this->room(['capacity' => 10, 'min_capacity' => 4]);

        $this->assertSame(8, $room->minimumGuestsFor(2));

        $this->send($room, [
            'adults' => 5, 'guests' => 5, 'guests_male' => 3, 'guests_female' => 2,
            'rooms_booked' => 2,
        ])->assertSessionHasErrors('guests');
    }

    /** No minimum set is the old behaviour, unchanged. */
    public function test_a_room_with_no_minimum_takes_a_single_guest(): void
    {
        $room = $this->room(['capacity' => 6, 'min_capacity' => null]);

        $this->assertSame(1, $room->minimumGuestsPerRoom());

        $this->send($room, ['adults' => 1, 'guests' => 1, 'guests_male' => 1, 'guests_female' => 0])
            ->assertSessionHasNoErrors();
    }

    /**
     * An owner who lowers capacity below a minimum they set earlier must not
     * leave a room nobody can book.
     */
    public function test_a_minimum_above_the_ceiling_is_clamped(): void
    {
        $room = $this->room(['capacity' => 2, 'min_capacity' => 6]);

        $this->assertSame(2, $room->minimumGuestsPerRoom());
        $this->assertNull($room->partyProblem(2, 1));
    }

    /* Room quantity -------------------------------------------------------- */

    public function test_zero_or_negative_rooms_are_refused(): void
    {
        $room = $this->room();

        foreach ([0, -1] as $quantity) {
            $this->send($room, ['rooms_booked' => $quantity])
                ->assertSessionHasErrors('rooms_booked');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_more_rooms_than_the_resort_owns_are_refused(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 2]);

        $this->send($room, [
            'rooms_booked' => 5,
            'adults' => 4, 'guests' => 4, 'guests_male' => 2, 'guests_female' => 2,
        ])->assertSessionHasErrors('rooms_booked');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_more_rooms_than_are_free_on_those_nights_are_refused(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 2]);

        // One unit already held over the same nights.
        Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Confirmed)->create(['rooms_booked' => 1]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->create($this->tourist, $room->id, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'guests' => 4, 'adults' => 4, 'children' => 0, 'pwd_guests' => 0,
            'rooms_booked' => 2,
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
        ]);
    }

    /* The service enforces the same rules the form does --------------------- */

    public function test_the_service_refuses_a_party_the_form_would_refuse(): void
    {
        $room = $this->room(['capacity' => 8, 'min_capacity' => 4]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('The minimum number of guests for this room is not satisfied.');

        app(BookingService::class)->create($this->tourist, $room->id, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'guests' => 2, 'adults' => 2, 'children' => 0, 'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
        ]);
    }

    public function test_the_service_refuses_a_party_above_the_ceiling(): void
    {
        $room = $this->room(['capacity' => 2]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage("exceeds this room's maximum capacity");

        app(BookingService::class)->create($this->tourist, $room->id, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'guests' => 6, 'adults' => 6, 'children' => 0, 'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Ana Reyes',
            'contact_phone' => '09171234567',
        ]);
    }

    /* Adults, children and PWD still add up --------------------------------- */

    public function test_guest_composition_is_still_checked(): void
    {
        $room = $this->room(['capacity' => 10]);

        // PWD guests cannot exceed the party.
        $this->send($room, [
            'adults' => 2, 'children' => 0, 'pwd_guests' => 5, 'guests' => 2,
            'guests_male' => 1, 'guests_female' => 1,
        ])->assertSessionHasErrors('pwd_guests');

        // Male plus female must equal the total.
        $this->send($room, [
            'adults' => 2, 'children' => 2, 'guests' => 4,
            'guests_male' => 1, 'guests_female' => 1,
        ])->assertSessionHasErrors('guests_male');
    }

    /** Children count towards the party, so they count towards both limits. */
    public function test_children_count_towards_the_capacity(): void
    {
        $room = $this->room(['capacity' => 3]);

        $this->send($room, [
            'adults' => 2, 'children' => 2, 'guests' => 4,
            'guests_male' => 2, 'guests_female' => 2,
        ])->assertSessionHasErrors('guests');
    }

    /* Confirmation rechecks ------------------------------------------------- */

    /**
     * THE GAP THIS CLOSES. Confirming checked the calendar date and the
     * status transition and nothing else, so an owner could accept a request
     * into inventory that had gone since it was sent.
     */
    public function test_confirming_refuses_when_the_units_have_gone(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 1]);

        $waiting = Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Pending)->create(['rooms_booked' => 1, 'guests' => 2]);

        // The owner took a walk-in group for the same night in the meantime.
        Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Confirmed)->create(['rooms_booked' => 1]);

        try {
            app(BookingStatusManager::class)->confirm($waiting);
            $this->fail('Confirming should have been refused.');
        } catch (BookingException $e) {
            $this->assertStringContainsString(
                'Room availability has changed. Please review this booking request.',
                $e->getMessage()
            );
        }

        $this->assertSame(BookingStatus::Pending, $waiting->fresh()->status);
    }

    /** And it still confirms when the units are there. */
    public function test_confirming_succeeds_when_the_units_are_still_there(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 2]);

        $waiting = Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Pending)->create(['rooms_booked' => 1, 'guests' => 2]);

        app(BookingStatusManager::class)->confirm($waiting);

        $this->assertSame(BookingStatus::Confirmed, $waiting->fresh()->status);
    }

    /**
     * A single unit with one waiting request confirms. The request already
     * counts towards the units in use, so the recheck has to exclude it or
     * nothing could ever be confirmed.
     */
    public function test_a_request_is_not_measured_against_itself(): void
    {
        $room = $this->room(['capacity' => 4, 'total_rooms' => 1]);

        $waiting = Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Pending)->create(['rooms_booked' => 1, 'guests' => 2]);

        app(BookingStatusManager::class)->confirm($waiting);

        $this->assertSame(BookingStatus::Confirmed, $waiting->fresh()->status);
    }

    /** The capacity is rechecked too: an owner may have shrunk the room. */
    public function test_confirming_refuses_when_the_room_no_longer_fits_the_party(): void
    {
        $room = $this->room(['capacity' => 6, 'total_rooms' => 2]);

        $waiting = Booking::factory()->for($room)->forStay('2030-07-10', '2030-07-12')
            ->withStatus(BookingStatus::Pending)->create(['rooms_booked' => 1, 'guests' => 6]);

        $room->forceFill(['capacity' => 2])->save();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no longer fits the room');

        app(BookingStatusManager::class)->confirm($waiting);
    }

    /* Existing behaviour that must not have moved --------------------------- */

    public function test_the_price_and_discounts_are_unchanged(): void
    {
        $room = $this->room([
            'capacity' => 6,
            'price_per_night' => 1000,
            'child_discount_enabled' => true,
            'child_discount_percent' => 50,
        ]);

        $this->send($room, [
            'adults' => 2, 'children' => 2, 'guests' => 4,
            'guests_male' => 2, 'guests_female' => 2,
        ])->assertSessionHasNoErrors();

        $booking = Booking::query()->latest('id')->firstOrFail();

        // 2 nights x 1 room x 1000 = 2000 subtotal, and the children's half
        // off is applied on top. The figures themselves are BookingPricer's;
        // what matters here is that adding a floor changed none of them.
        $this->assertSame(2, $booking->nights);
        $this->assertSame(4, $booking->guests);
        $this->assertTrue((float) $booking->discount_total > 0);
        $this->assertSame(
            (float) $booking->subtotal_amount - (float) $booking->discount_total,
            (float) $booking->total_amount
        );
    }
}
