<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TouristBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');
        $this->tourist = User::factory()->create();
        $this->room = Room::factory()->for(Resort::factory()->active())->create(['total_rooms' => 1, 'capacity' => 4]);
    }

    private function booking(BookingStatus $status = BookingStatus::Pending, string $in = '2030-07-10', string $out = '2030-07-13'): Booking
    {
        return Booking::factory()
            ->for($this->room)
            ->for($this->tourist, 'tourist')
            ->forStay($in, $out)
            ->withStatus($status)
            ->create();
    }

    /* Seeing your own reservations ---------------------------------------- */

    public function test_the_detail_page_shows_everything_a_guest_needs(): void
    {
        $booking = $this->booking(BookingStatus::Confirmed);
        $booking->forceFill(['special_requests' => 'Arriving late, around 9 PM.'])->save();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->booking_reference)
            ->assertSee($booking->resort->name)
            ->assertSee($booking->resort->contact_number)
            ->assertSee('Arriving late, around 9 PM.')
            ->assertSee('Confirmed')
            // The terms the guest agreed to, rather than a blanket claim
            // that nothing is ever paid here: a resort taking a deposit
            // through QRPh or GCash is paid on this site.
            ->assertSee('What you agreed to pay')
            ->assertSee('Total for the stay')
            ->assertSee('Get directions');
    }

    public function test_each_status_is_explained_in_plain_words(): void
    {
        $expectations = [
            [BookingStatus::Pending, 'Waiting for the resort'],
            [BookingStatus::Confirmed, 'Bring this reference with you'],
            [BookingStatus::Completed, 'This stay is finished'],
        ];

        foreach ($expectations as [$status, $text]) {
            $booking = $this->booking($status);

            $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
                ->assertOk()
                ->assertSee($text);
        }
    }

    public function test_a_declined_request_shows_the_resorts_reason(): void
    {
        $booking = $this->booking(BookingStatus::Rejected);
        $booking->forceFill(['owner_remarks' => 'Those rooms were taken by a walk-in group.'])->save();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('The resort could not take this booking')
            ->assertSee('Those rooms were taken by a walk-in group.');
    }

    public function test_a_guest_cannot_open_someone_elses_reservation(): void
    {
        $mine = $this->booking();
        $theirs = Booking::factory()->create();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $mine))->assertOk();
        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $theirs))->assertForbidden();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.index'))
            ->assertOk()
            ->assertSee($mine->booking_reference)
            ->assertDontSee($theirs->booking_reference);
    }

    public function test_guests_must_sign_in_to_see_a_reservation(): void
    {
        $booking = $this->booking();

        $this->get(route('tourist.bookings.show', $booking))->assertRedirect(route('login'));
    }

    public function test_the_booking_reference_is_used_in_the_address_not_the_id(): void
    {
        $booking = $this->booking();

        $this->assertStringContainsString($booking->booking_reference, route('tourist.bookings.show', $booking));
        $this->assertStringNotContainsString('/'.$booking->id, route('tourist.bookings.show', $booking));
    }

    /* Cancelling ----------------------------------------------------------- */

    public function test_a_guest_can_cancel_and_the_rooms_are_freed(): void
    {
        $booking = $this->booking(BookingStatus::Confirmed);

        $this->assertSame(0, app(AvailabilityService::class)->availableUnits($this->room, '2030-07-10', '2030-07-13'));

        $this->actingAs($this->tourist)
            ->patch(route('tourist.bookings.cancel', $booking), ['cancellation_reason' => 'Our trip was moved.'])
            ->assertRedirect(route('tourist.bookings.show', $booking))
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame($this->tourist->id, $booking->cancelled_by);
        $this->assertSame('Our trip was moved.', $booking->cancellation_reason);
        $this->assertSame(1, app(AvailabilityService::class)->availableUnits($this->room, '2030-07-10', '2030-07-13'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.cancelled', 'subject_id' => $booking->id]);
    }

    public function test_a_reason_is_optional(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->tourist)->patch(route('tourist.bookings.cancel', $booking))->assertRedirect();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame('Cancelled by the guest.', $booking->fresh()->cancellation_reason);
    }

    public function test_a_waiting_request_can_also_be_cancelled(): void
    {
        $booking = $this->booking(BookingStatus::Pending);

        $this->actingAs($this->tourist)->patch(route('tourist.bookings.cancel', $booking))->assertRedirect();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancelling_is_refused_once_the_stay_has_started(): void
    {
        $booking = $this->booking(BookingStatus::Confirmed);
        $this->travelTo('2030-07-10 08:00:00');

        $this->actingAs($this->tourist)->patch(route('tourist.bookings.cancel', $booking))->assertForbidden();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('too close to your arrival to cancel here');
    }

    public function test_a_guest_cannot_cancel_someone_elses_reservation(): void
    {
        $theirs = Booking::factory()->create();

        $this->actingAs($this->tourist)->patch(route('tourist.bookings.cancel', $theirs))->assertForbidden();
        $this->assertSame(BookingStatus::Pending, $theirs->fresh()->status);
    }

    public function test_finished_reservations_cannot_be_cancelled(): void
    {
        foreach ([BookingStatus::Rejected, BookingStatus::Cancelled, BookingStatus::Completed] as $status) {
            $booking = $this->booking($status);

            $this->actingAs($this->tourist)->patch(route('tourist.bookings.cancel', $booking))->assertForbidden();
            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_cancelling_rejects_get_requests(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->tourist)->get('/tourist/bookings/'.$booking->booking_reference.'/cancel')->assertStatus(405);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_the_cancel_button_only_appears_when_cancelling_is_possible(): void
    {
        $open = $this->booking(BookingStatus::Confirmed);
        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $open))
            ->assertSee('Cancel this reservation');

        $closed = $this->booking(BookingStatus::Completed);
        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $closed))
            ->assertDontSee('Cancel this reservation')
            ->assertSee('This reservation is closed');
    }

    /* The owner's side of a guest cancellation ----------------------------- */

    public function test_the_resort_sees_the_guests_cancellation(): void
    {
        $booking = $this->booking(BookingStatus::Confirmed);
        $owner = $this->room->resort->owner;

        $this->actingAs($this->tourist)
            ->patch(route('tourist.bookings.cancel', $booking), ['cancellation_reason' => 'Our trip was moved.']);

        $this->actingAs($owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Cancelled')
            ->assertSee('Our trip was moved.');
    }

    public function test_making_a_booking_lands_on_its_confirmation_page(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['capacity' => 4, 'total_rooms' => 2]);

        $response = $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-20',
            'check_out_date' => '2030-07-22',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ]);

        $booking = Booking::latest('id')->first();
        $response->assertRedirect(route('tourist.bookings.show', $booking));

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->booking_reference)
            ->assertSee('Waiting for the resort');
    }

    public function test_the_form_joins_the_two_name_fields_and_keeps_a_reply_address(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['capacity' => 4, 'total_rooms' => 2]);

        $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-20',
            'check_out_date' => '2030-07-22',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_first_name' => '  Bea  ',
            'contact_last_name' => 'Santos',
            'contact_email' => '  BEA@Example.TEST ',
            'contact_phone' => '0917 123 4567',
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
        ])->assertSessionHasNoErrors();

        $booking = Booking::latest('id')->sole();

        $this->assertSame('Bea Santos', $booking->contact_name);
        $this->assertSame('bea@example.test', $booking->contact_email);
        $this->assertSame('09171234567', $booking->contact_phone);
    }

    public function test_half_a_name_is_refused(): void
    {
        $room = Room::factory()->for(Resort::factory()->active())->create(['capacity' => 4, 'total_rooms' => 2]);

        $this->actingAs($this->tourist)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-20',
            'check_out_date' => '2030-07-22',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_first_name' => 'Bea',
            'contact_last_name' => '',
            'contact_phone' => '09171234567',
        ])->assertSessionHasErrors('contact_last_name');

        $this->assertDatabaseCount('bookings', 0);
    }
}
