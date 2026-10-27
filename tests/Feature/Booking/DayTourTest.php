<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Day and night tours: a room sold for part of a day rather than for
 * the night.
 *
 * THE POINT OF MOST OF THIS FILE IS THAT A TOUR HOLDS THE ROOM.
 *
 * AvailabilityService counts the nights in the half-open range
 * [check_in, check_out) and skips any booking whose two dates are equal.
 * A tour recorded on a single date would therefore hold no inventory at
 * all, and the same cottage could be sold to an unlimited number of day
 * groups — and to an overnight party on top. It is stored as [D, D+1)
 * so the sweep blocks the date exactly as one night does, with nights
 * left at zero because nobody slept there.
 */
class DayTourTest extends TestCase
{
    use RefreshDatabase;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->resort = Resort::factory()->active()->create([
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
        ]);
    }

    private function room(array $attributes = []): Room
    {
        return Room::factory()->for($this->resort)->create($attributes + [
            'capacity' => 6,
            'bed_count' => 3,
            'price_per_night' => 2000,
            'day_tour_price' => 1200,
            'night_tour_price' => 1500,
            'total_rooms' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $overrides = []): array
    {
        $payload = array_merge([
            'room' => $room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'origin' => 'pangasinan',
        ], $overrides);

        $guests = (int) $payload['adults'] + (int) $payload['children'];
        $payload['guests_male'] ??= intdiv($guests, 2);
        $payload['guests_female'] ??= $guests - intdiv($guests, 2);

        return $payload;
    }

    /** One active reservation per guest, so each booking needs its own. */
    private function book(Room $room, array $overrides = []): Booking
    {
        $this->actingAs(User::factory()->create())
            ->post(route('tourist.bookings.store'), $this->payload($room, $overrides))
            ->assertSessionHasNoErrors();

        return Booking::latest('id')->firstOrFail();
    }

    private function attempt(Room $room, array $overrides = [])
    {
        return $this->actingAs(User::factory()->create())
            ->post(route('tourist.bookings.store'), $this->payload($room, $overrides));
    }

    /* How a tour is stored ---------------------------------------------- */

    public function test_a_day_tour_is_one_date_with_no_nights(): void
    {
        $booking = $this->book($this->room(), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->assertSame(BookingType::DayTour, $booking->booking_type);
        $this->assertSame(0, $booking->nights);
        $this->assertSame('2030-07-10', $booking->check_in_date->toDateString());

        // The day after is how the room is held, not a night anybody slept.
        $this->assertSame('2030-07-11', $booking->check_out_date->toDateString());
    }

    public function test_a_tour_does_not_need_a_check_out_date_from_the_guest(): void
    {
        $payload = $this->payload($this->room(), [
            'booking_type' => 'night_tour',
            'check_in_date' => '2030-07-10',
        ]);

        unset($payload['check_out_date']);

        $this->actingAs(User::factory()->create())
            ->post(route('tourist.bookings.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '2030-07-11',
            Booking::latest('id')->sole()->check_out_date->toDateString()
        );
    }

    public function test_a_check_out_date_sent_with_a_tour_is_ignored(): void
    {
        // A stale date left in the folded-away box must not stretch a
        // day tour into a week.
        $booking = $this->book($this->room(), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-17',
        ]);

        $this->assertSame('2030-07-11', $booking->check_out_date->toDateString());
        $this->assertSame(0, $booking->nights);
    }

    public function test_an_unknown_booking_type_is_refused(): void
    {
        $this->attempt($this->room(), ['booking_type' => 'weekend'])
            ->assertSessionHasErrors('booking_type');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_booking_with_no_type_is_an_overnight_stay(): void
    {
        $booking = $this->book($this->room());

        $this->assertSame(BookingType::Overnight, $booking->booking_type);
        $this->assertSame(2, $booking->nights);
    }

    /* Holding the room -------------------------------------------------- */

    public function test_a_day_tour_holds_the_only_unit_against_a_second_tour(): void
    {
        $room = $this->room(); // One unit.

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->attempt($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ])->assertSessionHas('error');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_day_tour_holds_the_only_unit_against_a_night_tour(): void
    {
        $room = $this->room();

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->attempt($room, [
            'booking_type' => 'night_tour',
            'check_in_date' => '2030-07-10',
        ])->assertSessionHas('error');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_day_tour_holds_the_only_unit_against_an_overnight_stay(): void
    {
        $room = $this->room();

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        // A stay that sleeps through the night of the 10th.
        $this->attempt($room, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
        ])->assertSessionHas('error');

        $this->assertSame(1, Booking::count());
    }

    public function test_an_overnight_stay_holds_the_only_unit_against_a_day_tour(): void
    {
        $room = $this->room();

        $this->book($room, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
        ]);

        $this->attempt($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-11',
        ])->assertSessionHas('error');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_tour_frees_the_room_the_next_day(): void
    {
        $room = $this->room();

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        // The 11th is only where the hold ends, so it is still for sale.
        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-11',
        ]);

        $this->assertSame(2, Booking::count());
    }

    public function test_the_day_a_stay_checks_out_is_free_for_a_tour(): void
    {
        $room = $this->room();

        $this->book($room, [
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
        ]);

        // Same-day turnover: the stay is gone by noon on the 12th.
        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-12',
        ]);

        $this->assertSame(2, Booking::count());
    }

    public function test_a_second_unit_takes_a_second_tour_on_the_same_date(): void
    {
        $room = $this->room(['total_rooms' => 2]);

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->assertSame(2, Booking::count());
    }

    /* What a tour costs -------------------------------------------------- */

    public function test_a_tour_is_priced_for_the_booking_not_by_the_night(): void
    {
        $booking = $this->book($this->room(['total_rooms' => 3]), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
            'rooms_booked' => 2,
        ]);

        $this->assertSame('1200.00', $booking->unit_price);
        $this->assertSame('2400.00', $booking->subtotal_amount);
        $this->assertSame('2400.00', $booking->total_amount);
    }

    public function test_a_night_tour_carries_its_own_rate(): void
    {
        $booking = $this->book($this->room(), [
            'booking_type' => 'night_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->assertSame('1500.00', $booking->unit_price);
        $this->assertSame('1500.00', $booking->total_amount);
    }

    public function test_discounts_still_come_off_a_tour(): void
    {
        $room = $this->room([
            'day_tour_price' => 1000,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 20,
        ]);

        // Two guests, one of them a PWD: 20% off half the bill.
        $booking = $this->book($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
            'adults' => 2,
            'pwd_guests' => 1,
        ]);

        $this->assertSame('1000.00', $booking->subtotal_amount);
        $this->assertSame('100.00', $booking->pwd_discount_amount);
        $this->assertSame('900.00', $booking->total_amount);
    }

    /* What a resort has not agreed to sell ------------------------------- */

    public function test_a_room_with_no_tour_rate_cannot_be_booked_as_one(): void
    {
        $room = $this->room([
            'day_tour_price' => null,
            'night_tour_price' => null,
        ]);

        $this->attempt($room, [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_a_tour_rate_is_never_the_nightly_rate(): void
    {
        $room = $this->room(['day_tour_price' => null]);

        $this->assertNull($room->priceFor(BookingType::DayTour));
        $this->assertFalse($room->offers(BookingType::DayTour));
        $this->assertTrue($room->offers(BookingType::Overnight));
    }

    public function test_a_zero_tour_rate_means_the_room_is_not_sold_that_way(): void
    {
        $room = $this->room(['day_tour_price' => 0]);

        $this->assertNull($room->priceFor(BookingType::DayTour));
        $this->assertFalse($room->offers(BookingType::DayTour));
    }

    /* The hours a tour keeps --------------------------------------------- */

    public function test_a_day_tour_does_not_inherit_the_resorts_overnight_hours(): void
    {
        $payload = $this->payload($this->room(), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        unset($payload['check_in_time'], $payload['check_out_time']);

        $this->actingAs(User::factory()->create())
            ->post(route('tourist.bookings.store'), $payload)
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->sole();

        $this->assertSame('08:00', substr($booking->check_in_time, 0, 5));
        $this->assertSame('17:00', substr($booking->check_out_time, 0, 5));
    }

    public function test_a_night_tour_leaves_the_following_morning(): void
    {
        $payload = $this->payload($this->room(), [
            'booking_type' => 'night_tour',
            'check_in_date' => '2030-07-10',
        ]);

        unset($payload['check_in_time'], $payload['check_out_time']);

        $this->actingAs(User::factory()->create())
            ->post(route('tourist.bookings.store'), $payload)
            ->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->sole();

        $this->assertSame('18:00', substr($booking->check_in_time, 0, 5));
        $this->assertSame('06:00', substr($booking->check_out_time, 0, 5));
    }

    public function test_a_guest_may_still_name_their_own_hours(): void
    {
        $booking = $this->book($this->room(), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
            'check_in_time' => '09:30',
            'check_out_time' => '16:00',
        ]);

        $this->assertSame('09:30', substr($booking->check_in_time, 0, 5));
        $this->assertSame('16:00', substr($booking->check_out_time, 0, 5));
    }

    /* What the screens say ----------------------------------------------- */

    /** The form the resort page links to, with the dates it was searched for. */
    private function form(Room $room, array $query = [])
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.create', $query + [
                'room' => $room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-12',
            ]));
    }

    public function test_the_booking_form_offers_the_kinds_the_room_is_sold_as(): void
    {
        $this->form($this->room())
            ->assertOk()
            ->assertSee('What are you booking?')
            ->assertSee('Day tour')
            ->assertSee('Night tour');
    }

    public function test_the_booking_form_asks_nothing_of_an_overnight_only_room(): void
    {
        $room = $this->room([
            'day_tour_price' => null,
            'night_tour_price' => null,
        ]);

        $this->form($room)
            ->assertOk()
            ->assertDontSee('What are you booking?')
            ->assertSee('name="booking_type" value="overnight"', false);
    }

    public function test_one_date_is_enough_to_open_the_form(): void
    {
        // A day group is asked for one date, and a link carrying one is
        // not a half-finished search.
        $room = $this->room();

        $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.create', [
                'room' => $room->id,
                'check_in' => '2030-07-10',
            ]))
            ->assertOk()
            ->assertSee('What are you booking?')
            ->assertDontSee('Choose your dates on the resort page first.');
    }

    public function test_the_form_still_asks_for_dates_when_none_were_given(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.create', ['room' => $this->room()->id]))
            ->assertOk()
            ->assertSee('Choose your dates on the resort page first.');
    }

    public function test_a_tour_shows_the_guest_one_date_and_no_nights(): void
    {
        $booking = $this->book($this->room(), [
            'booking_type' => 'day_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $response = $this->actingAs($booking->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Day tour')
            ->assertSee('Wednesday, Jul 10, 2030');

        // The 11th is bookkeeping. Telling a day group to come back
        // tomorrow is exactly the mistake this guards against.
        $response->assertDontSee('Jul 11, 2030');
        $response->assertDontSee('Nights');
    }

    public function test_an_overnight_stay_still_shows_both_dates(): void
    {
        $booking = $this->book($this->room());

        $this->actingAs($booking->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Nights')
            ->assertSee('Wednesday, Jul 10, 2030')
            ->assertSee('Friday, Jul 12, 2030');
    }

    public function test_the_list_of_reservations_names_the_kind(): void
    {
        $booking = $this->book($this->room(), [
            'booking_type' => 'night_tour',
            'check_in_date' => '2030-07-10',
        ]);

        $this->assertSame('Night tour', $booking->durationLabel());
        $this->assertSame('Jul 10, 2030', $booking->dateRangeLabel());

        $this->actingAs($booking->tourist)
            ->get(route('tourist.bookings.index'))
            ->assertOk()
            ->assertSee('Night tour');
    }

    public function test_an_overnight_stay_is_still_counted_in_nights(): void
    {
        $booking = $this->book($this->room());

        $this->assertSame('2 nights', $booking->durationLabel());
        $this->assertSame('Jul 10 to Jul 12, 2030', $booking->dateRangeLabel());
    }

    public function test_the_resort_page_publishes_the_tour_rates(): void
    {
        $room = $this->room();

        $this->get(route('resorts.show', $this->resort))
            ->assertOk()
            ->assertSee($room->name)
            ->assertSee('Day tour')
            ->assertSee('Night tour');
    }
}
