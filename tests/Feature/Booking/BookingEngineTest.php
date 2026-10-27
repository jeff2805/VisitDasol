<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->tourist = User::factory()->create(['first_name' => 'Bea', 'last_name' => 'Santos']);
        $this->resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $this->room = Room::factory()->for($this->resort)->create([
            'name' => 'Seaview Deluxe',
            'capacity' => 2,
            'price_per_night' => 2500,
            'total_rooms' => 2,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'room' => $this->room->id,
            'check_in_date' => '2030-10-10',
            'check_out_date' => '2030-10-13',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '0917 123 4567',
            'special_requests' => null,
            'origin' => 'pangasinan',
        ], $overrides);

        /*
         * The sex split follows whatever number of guests the test asked for,
         * unless the test set it itself.
         *
         * The office's return requires it and the form refuses a split that
         * does not add up, so hard-coding one here would make every test that
         * changes the party size fail for a reason it is not about.
         */
        if (! array_key_exists('guests_male', $payload) && ! array_key_exists('guests_female', $payload)) {
            $guests = max(0, (int) $payload['guests']);

            $payload['guests_male'] = intdiv($guests, 2);
            $payload['guests_female'] = $guests - intdiv($guests, 2);
        }

        return $payload;
    }

    private function book(array $overrides = [])
    {
        return $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), $this->payload($overrides));
    }

    /* The happy path ----------------------------------------------------- */

    public function test_a_tourist_can_request_a_room(): void
    {
        // Phase 13 sends the guest to the confirmation page for this reservation.
        $this->book()->assertRedirect(route('tourist.bookings.show', Booking::sole()))->assertSessionHas('success');

        $booking = Booking::sole();
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame($this->tourist->id, $booking->user_id);
        $this->assertSame($this->resort->id, $booking->resort_id);
        $this->assertSame($this->room->id, $booking->room_id);
        $this->assertSame(3, $booking->nights);
        $this->assertSame('2500.00', $booking->unit_price);
        $this->assertSame('7500.00', $booking->total_amount);
        $this->assertSame('09171234567', $booking->contact_phone);
        $this->assertMatchesRegularExpression('/^VD-20301010-[A-HJ-NP-Z2-9]{5}$/', $booking->booking_reference);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.created', 'subject_id' => $booking->id]);
    }

    public function test_several_rooms_multiply_the_total(): void
    {
        $this->book(['rooms_booked' => 2, 'guests' => 4]);

        $booking = Booking::sole();
        $this->assertSame(2, $booking->rooms_booked);
        $this->assertSame('15000.00', $booking->total_amount);
    }

    /* Nothing from the browser is trusted --------------------------------- */

    public function test_a_price_sent_with_the_form_is_ignored(): void
    {
        $this->book(['unit_price' => 1, 'total_amount' => 1, 'price_per_night' => 1]);

        $booking = Booking::sole();
        $this->assertSame('2500.00', $booking->unit_price);
        $this->assertSame('7500.00', $booking->total_amount);
    }

    public function test_another_persons_id_or_resort_cannot_be_forced(): void
    {
        $otherTourist = User::factory()->create();
        $otherResort = Resort::factory()->active()->create();

        $this->book([
            'user_id' => $otherTourist->id,
            'resort_id' => $otherResort->id,
            'status' => 'confirmed',
            'booking_reference' => 'VD-FAKE-00001',
        ]);

        $booking = Booking::sole();
        $this->assertSame($this->tourist->id, $booking->user_id);
        $this->assertSame($this->resort->id, $booking->resort_id);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNotSame('VD-FAKE-00001', $booking->booking_reference);
    }

    /* Dates ---------------------------------------------------------------- */

    public function test_dates_must_make_sense(): void
    {
        $this->book(['check_in_date' => '2030-05-30'])->assertSessionHasErrors('check_in_date');
        $this->book(['check_out_date' => '2030-10-10'])->assertSessionHasErrors('check_out_date');
        $this->book(['check_in_date' => '2030-10-13', 'check_out_date' => '2030-10-10'])->assertSessionHasErrors('check_out_date');
        $this->book(['check_in_date' => '2032-01-01', 'check_out_date' => '2032-01-03'])->assertSessionHasErrors('check_in_date');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_stay_longer_than_the_limit_is_refused(): void
    {
        $this->book(['check_in_date' => '2030-10-01', 'check_out_date' => '2030-11-15'])
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    /* Capacity -------------------------------------------------------------- */

    public function test_more_guests_than_the_room_takes_are_refused(): void
    {
        /*
         * Answered on the guest-count field rather than as a message at
         * the top of the page, because the guest has to change a number
         * they can see to fix it. BookingService refuses the same party
         * again when it is handed one, which is what catches a room
         * whose capacity changed while the form was open.
         */
        $this->book(['guests' => 3])->assertSessionHasErrors('guests');
        $this->assertSame(0, Booking::count());

        // Two rooms do fit four guests.
        $this->book(['guests' => 4, 'rooms_booked' => 2])->assertSessionHasNoErrors();
        $this->assertSame(1, Booking::count());
    }

    /* Availability ---------------------------------------------------------- */

    public function test_the_last_unit_cannot_be_booked_twice(): void
    {
        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13', 2)->confirmed()->create();

        $this->book()->assertSessionHas('error');
        $this->assertSame(1, Booking::count());
    }

    public function test_a_partly_free_room_type_allows_what_is_left(): void
    {
        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13')->confirmed()->create();

        $this->book(['rooms_booked' => 2, 'guests' => 4])->assertSessionHas('error');
        $this->book(['rooms_booked' => 1])->assertSessionHasNoErrors();

        $this->assertSame(2, Booking::count());
    }

    public function test_a_guest_may_arrive_on_the_day_another_leaves(): void
    {
        Booking::factory()->for($this->room)->forStay('2030-10-07', '2030-10-10', 2)->confirmed()->create();

        $this->book()->assertSessionHasNoErrors();
        $this->assertSame(2, Booking::count());
    }

    public function test_cancelled_and_rejected_stays_free_the_room(): void
    {
        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13', 2)->cancelled()->create();
        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13', 2)->rejected()->create();

        $this->book()->assertSessionHasNoErrors();
        $this->assertSame(3, Booking::count());
    }

    public function test_a_pending_request_still_holds_the_room(): void
    {
        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13', 2)->create();

        $this->book()->assertSessionHas('error');
        $this->assertSame(1, Booking::count());
    }

    /* What may be booked ---------------------------------------------------- */

    public static function unbookableCases(): array
    {
        return [
            'room switched off' => ['room_off'],
            'resort waiting for review' => ['resort_pending'],
            'resort taken offline' => ['resort_offline'],
            'owner deactivated' => ['owner_off'],
        ];
    }

    #[DataProvider('unbookableCases')]
    public function test_rooms_that_are_not_bookable_are_refused(string $case): void
    {
        match ($case) {
            'room_off' => $this->room->forceFill(['status' => 'inactive'])->save(),
            'resort_pending' => $this->resort->forceFill(['status' => 'pending'])->save(),
            'resort_offline' => $this->resort->forceFill(['status' => 'inactive'])->save(),
            'owner_off' => $this->resort->owner->forceFill(['status' => UserStatus::Inactive])->save(),
        };

        $this->book()->assertSessionHas('error');
        $this->assertSame(0, Booking::count());
    }

    public function test_only_active_tourists_may_book(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $admin = User::factory()->superAdmin()->create();

        foreach ([$owner, $admin] as $other) {
            $this->actingAs($other)->post(route('tourist.bookings.store'), $this->payload())->assertForbidden();
            $this->actingAs($other)->get(route('tourist.bookings.create', ['room' => $this->room->id]))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->post(route('tourist.bookings.store'), $this->payload())->assertRedirect(route('login'));
        $this->assertSame(0, Booking::count());
    }

    public function test_a_guest_is_sent_to_sign_in_and_then_back_to_the_booking_form(): void
    {
        $url = route('tourist.bookings.create', [
            'room' => $this->room->id, 'check_in' => '2030-10-10', 'check_out' => '2030-10-13', 'guests' => 2,
        ]);

        $this->get($url)->assertRedirect(route('login'));

        $response = $this->post(route('login'), ['email' => $this->tourist->email, 'password' => 'password']);

        // The query string may come back in a different order, so compare the parts.
        $target = $response->headers->get('Location');
        $this->assertStringContainsString('/tourist/bookings/create', $target);
        foreach (['room='.$this->room->id, 'check_in=2030-10-10', 'check_out=2030-10-13', 'guests=2'] as $part) {
            $this->assertStringContainsString($part, urldecode($target));
        }
    }

    /* Limits ----------------------------------------------------------------- */

    public function test_too_many_waiting_requests_are_refused(): void
    {
        $limit = config('visitdasol.booking.max_pending_per_tourist');
        Booking::factory()->count($limit)->for($this->tourist, 'tourist')->create();

        $this->book()->assertSessionHas('error');
        $this->assertSame($limit, Booking::count());
    }

    /* Pages -------------------------------------------------------------------- */

    public function test_the_resort_page_shows_what_is_free(): void
    {
        $this->get(route('resorts.show', [$this->resort, 'check_in' => '2030-10-10', 'check_out' => '2030-10-13', 'guests' => 2]))
            ->assertOk()
            ->assertSee('2 rooms free')
            ->assertSee('Request this room');

        Booking::factory()->for($this->room)->forStay('2030-10-10', '2030-10-13', 2)->confirmed()->create();

        $this->get(route('resorts.show', [$this->resort, 'check_in' => '2030-10-10', 'check_out' => '2030-10-13', 'guests' => 2]))
            ->assertOk()
            ->assertSee('Fully booked for these dates')
            ->assertDontSee('Request this room');
    }

    public function test_the_booking_form_shows_a_server_calculated_total(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id, 'check_in' => '2030-10-10', 'check_out' => '2030-10-13', 'guests' => 2,
            ]))
            ->assertOk()
            ->assertSee('Seaview Deluxe')
            ->assertSee('₱7,500')
            ->assertSee('Confirm reservation')
            // The summary reads like a bill, so the page has to be plain that
            // sending a request charges nothing.
            ->assertSee('Nothing is charged now');
    }

    public function test_the_booking_form_refuses_a_room_that_is_not_bookable(): void
    {
        $this->room->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', ['room' => $this->room->id]))
            ->assertRedirect(route('resorts.index'));
    }

    public function test_the_list_shows_only_your_own_reservations(): void
    {
        $mine = Booking::factory()->for($this->tourist, 'tourist')->for($this->room)->create();
        $theirs = Booking::factory()->create();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.index'))
            ->assertOk()
            ->assertSee($mine->booking_reference)
            ->assertDontSee($theirs->booking_reference);
    }
}
