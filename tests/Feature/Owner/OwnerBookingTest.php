<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');
        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($this->resort)->create(['total_rooms' => 2, 'capacity' => 4]);
    }

    private function booking(array $state = []): Booking
    {
        return Booking::factory()
            ->for($this->room)
            ->forStay($state['in'] ?? '2030-07-10', $state['out'] ?? '2030-07-13')
            ->withStatus($state['status'] ?? BookingStatus::Pending)
            ->create();
    }

    /* Seeing reservations ------------------------------------------------ */

    public function test_an_owner_sees_only_their_own_reservations(): void
    {
        $mine = $this->booking();
        $theirs = Booking::factory()->create();

        $this->actingAs($this->owner)->get(route('owner.bookings.index'))
            ->assertOk()
            ->assertSee($mine->booking_reference)
            ->assertDontSee($theirs->booking_reference);

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $theirs))->assertForbidden();
    }

    public function test_reservations_can_be_filtered(): void
    {
        $pending = $this->booking();
        $confirmed = $this->booking(['status' => BookingStatus::Confirmed, 'in' => '2030-08-01', 'out' => '2030-08-03']);
        $otherRoom = Room::factory()->for($this->resort)->create();
        $otherRoomBooking = Booking::factory()->for($otherRoom)->forStay('2030-09-01', '2030-09-02')->create();

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'confirmed']))
            ->assertSee($confirmed->booking_reference)->assertDontSee($pending->booking_reference);

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['room' => $otherRoom->id]))
            ->assertSee($otherRoomBooking->booking_reference)->assertDontSee($pending->booking_reference);

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['from' => '2030-08-25']))
            ->assertSee($otherRoomBooking->booking_reference)->assertDontSee($pending->booking_reference);

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['q' => $confirmed->booking_reference]))
            ->assertSee($confirmed->booking_reference)->assertDontSee($pending->booking_reference);
    }

    public function test_the_detail_page_shows_what_the_owner_needs(): void
    {
        $booking = $this->booking();
        $booking->forceFill([
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'special_requests' => 'Arriving late, around 9 PM.',
        ])->save();

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Bea Santos')
            ->assertSee('09171234567')
            ->assertSee('Arriving late, around 9 PM.')
            ->assertSee('Confirm')
            ->assertSee('Decline');
    }

    /* Decisions ----------------------------------------------------------- */

    public function test_confirming_a_request(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking))
            ->assertRedirect()->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotNull($booking->confirmed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.confirmed', 'subject_id' => $booking->id]);

        // The guest sees the new status.
        $this->actingAs($booking->tourist)->get(route('tourist.bookings.index'))
            ->assertOk()->assertSee('Confirmed');
    }

    public function test_declining_needs_a_reason_and_frees_the_dates(): void
    {
        $this->room->forceFill(['total_rooms' => 1])->save();
        $booking = $this->booking();

        $this->assertSame(0, app(AvailabilityService::class)->availableUnits($this->room, '2030-07-10', '2030-07-13'));

        $this->actingAs($this->owner)->patch(route('owner.bookings.reject', $booking), ['owner_remarks' => ''])
            ->assertSessionHasErrors('owner_remarks');
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);

        $this->actingAs($this->owner)->patch(route('owner.bookings.reject', $booking), [
            'owner_remarks' => 'Those rooms were taken by a walk-in group.',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertSame('Those rooms were taken by a walk-in group.', $booking->owner_remarks);
        $this->assertSame(1, app(AvailabilityService::class)->availableUnits($this->room, '2030-07-10', '2030-07-13'));
    }

    public function test_a_stay_can_be_marked_completed_only_after_check_out(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->owner)->patch(route('owner.bookings.complete', $booking))->assertForbidden();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        /*
         * Checkout morning, but before the checkout hour. The guest is
         * still in the room, so the stay is not over yet and the resort
         * is told so rather than filing it as finished.
         */
        $this->travelTo('2030-07-13 10:00:00');

        $this->actingAs($this->owner)->patch(route('owner.bookings.complete', $booking));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        // Past noon, so the stay really has ended.
        $this->travelTo('2030-07-13 13:00:00');

        $this->actingAs($this->owner)->patch(route('owner.bookings.complete', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_a_confirmed_stay_can_be_called_off_with_a_reason(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->owner)->patch(route('owner.bookings.cancel', $booking), ['cancellation_reason' => ''])
            ->assertSessionHasErrors('cancellation_reason');

        $this->actingAs($this->owner)->patch(route('owner.bookings.cancel', $booking), [
            'cancellation_reason' => 'Storm damage to the cottages, repairs under way.',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame($this->owner->id, $booking->cancelled_by);
        $this->assertStringContainsString('Storm damage', $booking->cancellation_reason);
    }

    public function test_a_stay_that_already_started_cannot_be_called_off(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);
        $this->travelTo('2030-07-11 09:00:00');

        $this->actingAs($this->owner)->patch(route('owner.bookings.cancel', $booking), [
            'cancellation_reason' => 'Trying to cancel during the stay.',
        ])->assertForbidden();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_request_whose_arrival_date_has_passed_can_only_be_declined(): void
    {
        $booking = $this->booking();
        $this->travelTo('2030-07-11 09:00:00');

        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking))->assertForbidden();

        $this->actingAs($this->owner)->patch(route('owner.bookings.reject', $booking), [
            'owner_remarks' => 'This request was not answered in time.',
        ])->assertRedirect();

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }

    public function test_finished_reservations_cannot_be_changed_again(): void
    {
        foreach ([BookingStatus::Rejected, BookingStatus::Cancelled, BookingStatus::Completed] as $status) {
            $booking = $this->booking(['status' => $status]);

            $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $booking))->assertForbidden();
            $this->actingAs($this->owner)->patch(route('owner.bookings.complete', $booking))->assertForbidden();
            $this->actingAs($this->owner)
                ->patch(route('owner.bookings.reject', $booking), ['owner_remarks' => 'Changing my mind here.'])
                ->assertForbidden();

            $this->assertSame($status, $booking->fresh()->status);
        }
    }

    public function test_an_owner_cannot_decide_on_another_resorts_reservation(): void
    {
        $theirs = Booking::factory()->create();

        $this->actingAs($this->owner)->patch(route('owner.bookings.confirm', $theirs))->assertForbidden();
        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.reject', $theirs), ['owner_remarks' => 'Not my reservation at all.'])
            ->assertForbidden();

        $this->assertSame(BookingStatus::Pending, $theirs->fresh()->status);
    }

    public function test_tourists_and_the_office_cannot_use_the_owner_screens(): void
    {
        $booking = $this->booking();

        foreach ([User::factory()->create(), User::factory()->superAdmin()->create()] as $other) {
            $this->actingAs($other)->get(route('owner.bookings.index'))->assertForbidden();
            $this->actingAs($other)->patch(route('owner.bookings.confirm', $booking))->assertForbidden();
        }

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_decisions_reject_get_requests(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)->get('/owner/bookings/'.$booking->booking_reference.'/confirm')->assertStatus(405);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    /* Availability calendar ----------------------------------------------- */

    public function test_the_calendar_shows_rooms_free_each_night(): void
    {
        $this->booking(['status' => BookingStatus::Confirmed, 'in' => '2030-06-10', 'out' => '2030-06-12']);

        $response = $this->actingAs($this->owner)->get(route('owner.availability', ['month' => '2030-06']))
            ->assertOk()
            ->assertSee('June 2030')
            ->assertSee($this->room->name);

        $calendar = $response->viewData('calendar')[$this->room->id];

        $this->assertSame(2, $calendar['2030-06-09'], 'Nothing booked the night before.');
        $this->assertSame(1, $calendar['2030-06-10']);
        $this->assertSame(1, $calendar['2030-06-11']);
        $this->assertSame(2, $calendar['2030-06-12'], 'The check-out day is free again.');
    }

    public function test_the_calendar_can_be_paged_by_month(): void
    {
        $this->actingAs($this->owner)->get(route('owner.availability', ['month' => '2030-08']))
            ->assertOk()->assertSee('August 2030');

        // A nonsense month falls back to this month rather than breaking.
        $this->actingAs($this->owner)->get(route('owner.availability', ['month' => 'not-a-month']))
            ->assertOk()->assertSee('June 2030');
    }

    public function test_the_owner_dashboard_counts_waiting_requests(): void
    {
        $this->booking();
        $this->booking(['in' => '2030-08-01', 'out' => '2030-08-02']);
        $this->booking(['status' => BookingStatus::Confirmed, 'in' => '2030-09-01', 'out' => '2030-09-03']);

        $this->actingAs($this->owner)->get(route('owner.dashboard'))
            ->assertOk()
            ->assertViewHas('waitingCount', 2)
            ->assertSee('Requests waiting for you')
            ->assertSee('Next confirmed stays');
    }
}
