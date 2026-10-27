<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telling a guest they already have a booking BEFORE they fill a form in.
 *
 * The rule itself is older than this warning and still enforced in
 * BookingService. What is tested here is that the guest finds out early,
 * is told which booking is in the way, and is given a way out of it —
 * rather than filling in a whole form and losing it at the last press.
 */
class DoubleBookingWarningTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Resort $first;

    private Resort $second;

    private Room $firstRoom;

    private Room $secondRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->tourist = User::factory()->create();

        $this->first = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $this->second = Resort::factory()->active()->create(['name' => 'Coral Cove Resort']);

        $this->firstRoom = Room::factory()->for($this->first)->create([
            'name' => 'Seaview Deluxe', 'capacity' => 4, 'total_rooms' => 3,
        ]);

        $this->secondRoom = Room::factory()->for($this->second)->create([
            'capacity' => 4, 'total_rooms' => 3,
        ]);
    }

    private function holding(BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return Booking::factory()
            ->for($this->firstRoom)
            ->for($this->first)
            ->for($this->tourist, 'tourist')
            ->forStay('2030-07-10', '2030-07-13')
            ->withStatus($status)
            ->create();
    }

    private function formFor(Room $room): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->tourist)->get(route('tourist.bookings.create', [
            'room' => $room->id,
            'check_in' => '2030-08-01',
            'check_out' => '2030-08-03',
            'guests' => 2,
        ]));
    }

    /* The warning on the booking form --------------------------------------- */

    public function test_the_form_is_not_even_drawn_when_a_booking_is_already_held(): void
    {
        $held = $this->holding();

        $response = $this->formFor($this->secondRoom)->assertOk();

        // The warning, naming what is in the way.
        $response->assertSee('You already have a booking')
            ->assertSee($held->booking_reference)
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Go to this booking');

        // And none of the form a guest would otherwise have filled in.
        $response->assertDontSee('Who is staying')
            ->assertDontSee('Confirm reservation')
            ->assertDontSee('name="contact_phone"', false);
    }

    public function test_the_warning_offers_the_way_out(): void
    {
        $held = $this->holding();

        $this->formFor($this->secondRoom)
            ->assertOk()
            ->assertSee('Cancel it and book elsewhere')
            ->assertSee(route('tourist.bookings.cancel', $held), false);
    }

    /**
     * A guest who cannot cancel online needs to be told that, not left
     * looking for a button that was deliberately left out.
     */
    public function test_a_stay_too_close_to_cancel_says_so_instead(): void
    {
        Booking::factory()
            ->for($this->firstRoom)->for($this->first)->for($this->tourist, 'tourist')
            ->forStay('2030-06-01', '2030-06-04')
            ->confirmed()
            ->create();

        $this->formFor($this->secondRoom)
            ->assertOk()
            ->assertSee('too close to your arrival to cancel online')
            ->assertDontSee('Cancel it and book elsewhere');
    }

    public function test_a_waiting_request_blocks_just_as_a_confirmed_one_does(): void
    {
        $this->holding(BookingStatus::Pending);

        $this->formFor($this->secondRoom)
            ->assertOk()
            ->assertSee('You already have a booking');
    }

    public function test_a_second_room_at_the_same_resort_is_blocked_too(): void
    {
        $this->holding();

        $another = Room::factory()->for($this->first)->create(['capacity' => 2, 'total_rooms' => 2]);

        $this->formFor($another)->assertOk()->assertSee('You already have a booking');
    }

    /* Finished stays block nothing -------------------------------------------- */

    public function test_a_finished_stay_does_not_stand_in_the_way(): void
    {
        foreach ([BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Completed] as $status) {
            Booking::query()->delete();

            $this->holding($status);

            $this->formFor($this->secondRoom)
                ->assertOk()
                ->assertSee('Who is staying')
                ->assertDontSee('You already have a booking');
        }
    }

    public function test_a_guest_with_nothing_booked_sees_the_ordinary_form(): void
    {
        $this->formFor($this->secondRoom)
            ->assertOk()
            ->assertSee('Who is staying')
            ->assertSee('Confirm reservation');
    }

    /* The warning on the resort page ------------------------------------------- */

    public function test_the_resort_page_warns_before_the_guest_even_clicks(): void
    {
        $this->holding();

        $this->actingAs($this->tourist)
            ->get(route('resorts.show', [
                $this->second,
                'check_in' => '2030-08-01',
                'check_out' => '2030-08-03',
                'guests' => 2,
            ]))
            ->assertOk()
            ->assertSee('You already have a booking at')
            ->assertSee('Salt Breeze Resort')
            // The button no longer invites a click that leads nowhere.
            ->assertSee('Finish your other booking first')
            ->assertDontSee('Request this room');
    }

    public function test_the_resort_page_is_unchanged_for_everyone_else(): void
    {
        $this->holding();

        /*
         * A visitor who is not signed in has no booking of their own to
         * be warned about, and must not be shown somebody else's.
         *
         * The other resort's NAME is not checked here: it appears on
         * this page anyway, in the "nearby places to stay" list, and
         * asserting on it would be testing the wrong thing.
         */
        $this->get(route('resorts.show', [
            $this->second, 'check_in' => '2030-08-01', 'check_out' => '2030-08-03', 'guests' => 2,
        ]))
            ->assertOk()
            ->assertDontSee('You already have a booking')
            ->assertDontSee('Finish your other booking first')
            ->assertSee('Request this room');
    }

    public function test_an_owner_browsing_is_not_warned_about_anything(): void
    {
        $this->holding();

        $owner = User::factory()->resortOwner()->create();

        $this->actingAs($owner)
            ->get(route('resorts.show', $this->second))
            ->assertOk()
            ->assertDontSee('You already have a booking');
    }

    /* The warning and the rule must agree --------------------------------------- */

    /**
     * The warning is drawn when the page loads. A guest with two tabs
     * open could still get a form through, so the rule is still enforced
     * where it counts — and the two must answer the same question.
     */
    public function test_the_rule_still_refuses_a_form_that_got_through(): void
    {
        $held = $this->holding();

        $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $this->secondRoom->id,
            'check_in_date' => '2030-08-01',
            'check_out_date' => '2030-08-03',
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ])->assertSessionHas('error');

        // Still only the one reservation.
        $this->assertSame(1, Booking::count());
        $this->assertSame($held->id, Booking::sole()->id);
    }

    public function test_the_warning_and_the_rule_read_the_same_bookings(): void
    {
        $service = app(BookingService::class);

        $this->assertNull($service->activeBookingFor($this->tourist));

        $expected = [
            [BookingStatus::Pending, true],
            [BookingStatus::Confirmed, true],
            [BookingStatus::Cancelled, false],
            [BookingStatus::Rejected, false],
            [BookingStatus::Completed, false],
        ];

        foreach ($expected as [$status, $blocks]) {
            Booking::query()->delete();
            $this->holding($status);

            $this->assertSame(
                $blocks,
                $service->activeBookingFor($this->tourist) !== null,
                "A {$status->value} stay should ".($blocks ? '' : 'not ').'block another booking.'
            );
        }
    }
}
