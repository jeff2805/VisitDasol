<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingRescheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Moving a reservation to different dates.
 *
 * THE ONE RULE EVERYTHING HERE TURNS ON: only a request the resort has not
 * answered yet may move. A pending request is nobody's arrangement — not
 * accepted, nothing paid — and changing it costs only the guest's own second
 * thoughts. A confirmed stay is something the resort has planned around, and
 * a guest who could still move it would be rewriting it alone.
 */
class RescheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->tourist = User::factory()->create();
        $this->resort = Resort::factory()->active()->create();
        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'total_rooms' => 1,
            'price_per_night' => 1000,
        ]);
    }

    private function booking(array $extra = []): Booking
    {
        return Booking::factory()
            ->for($this->resort)->for($this->room)->for($this->tourist, 'tourist')
            ->forStay('2030-06-20', '2030-06-22')
            ->create(array_merge([
                'status' => BookingStatus::Pending,
                'guests' => 2, 'adults' => 2, 'rooms_booked' => 1,
            ], $extra));
    }

    private function move(Booking $booking, string $in, ?string $out = '2030-07-05')
    {
        return $this->actingAs($this->tourist)->patch(
            route('tourist.bookings.reschedule', $booking),
            array_filter(['check_in_date' => $in, 'check_out_date' => $out]),
        );
    }

    /* The happy path ----------------------------------------------------- */

    public function test_a_waiting_request_moves_to_new_dates(): void
    {
        Notification::fake();

        $booking = $this->booking();

        $this->move($booking, '2030-07-03', '2030-07-05')
            ->assertRedirect(route('tourist.bookings.show', $booking));

        $fresh = $booking->fresh();

        $this->assertSame('2030-07-03', $fresh->check_in_date->toDateString());
        $this->assertSame('2030-07-05', $fresh->check_out_date->toDateString());
        $this->assertSame(BookingStatus::Pending, $fresh->status, 'It is still waiting.');
    }

    public function test_the_bill_is_worked_out_again_for_the_new_dates(): void
    {
        Notification::fake();

        $booking = $this->booking();
        $this->assertSame(2, $booking->nights);

        // Two nights becomes four.
        $this->move($booking, '2030-07-01', '2030-07-05');

        $fresh = $booking->fresh();

        $this->assertSame(4, $fresh->nights);
        $this->assertSame('4000.00', $fresh->total_amount, 'Four nights at 1000.');
    }

    public function test_the_resort_is_told_the_dates_changed(): void
    {
        Notification::fake();

        $booking = $this->booking();
        $this->move($booking, '2030-07-03', '2030-07-05');

        Notification::assertSentTo($this->resort->owner, BookingRescheduled::class);
    }

    public function test_the_room_is_freed_on_the_old_dates_and_held_on_the_new(): void
    {
        Notification::fake();

        $availability = app(\App\Services\AvailabilityService::class);
        $booking = $this->booking();

        $this->assertSame(0, $availability->availableUnits($this->room, '2030-06-20', '2030-06-22'));

        $this->move($booking, '2030-07-03', '2030-07-05');

        $this->assertSame(1, $availability->availableUnits($this->room, '2030-06-20', '2030-06-22'),
            'The old dates go back to other guests.');
        $this->assertSame(0, $availability->availableUnits($this->room, '2030-07-03', '2030-07-05'),
            'The new dates are held.');
    }

    /**
     * Moving by a single night must not be refused by the very rooms the
     * booking already holds.
     */
    public function test_a_booking_does_not_block_its_own_move(): void
    {
        Notification::fake();

        $booking = $this->booking();

        $this->move($booking, '2030-06-21', '2030-06-23')
            ->assertSessionHasNoErrors();

        $this->assertSame('2030-06-21', $booking->fresh()->check_in_date->toDateString());
    }

    /* Pending only — the rule the feature exists around ------------------ */

    public function test_a_confirmed_stay_cannot_be_moved(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->move($booking, '2030-07-03', '2030-07-05')->assertForbidden();

        $this->assertSame('2030-06-20', $booking->fresh()->check_in_date->toDateString());
    }

    public function test_a_cancelled_or_rejected_reservation_cannot_be_moved(): void
    {
        foreach ([BookingStatus::Cancelled, BookingStatus::Rejected, BookingStatus::Completed] as $status) {
            $booking = $this->booking(['status' => $status]);

            $this->move($booking, '2030-07-03', '2030-07-05')->assertForbidden();

            $this->assertSame(
                '2030-06-20',
                $booking->fresh()->check_in_date->toDateString(),
                "A {$status->value} reservation moved."
            );
        }
    }

    /**
     * The page may have been open while the resort answered.
     */
    public function test_a_stay_confirmed_a_second_ago_is_refused_by_the_service(): void
    {
        Notification::fake();

        $booking = $this->booking();

        // The policy would allow it; the service asks again under the lock.
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

        $this->expectException(\App\Exceptions\BookingException::class);
        app(\App\Services\BookingService::class)->reschedule($booking, [
            'check_in_date' => '2030-07-03',
            'check_out_date' => '2030-07-05',
        ]);
    }

    /* Everything else ---------------------------------------------------- */

    public function test_another_guest_cannot_move_somebody_elses_reservation(): void
    {
        $booking = $this->booking();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->patch(route('tourist.bookings.reschedule', $booking), [
                'check_in_date' => '2030-07-03',
                'check_out_date' => '2030-07-05',
            ])
            ->assertForbidden();
    }

    public function test_dates_that_are_taken_are_refused(): void
    {
        Notification::fake();

        // The only unit is taken by somebody else in July.
        Booking::factory()
            ->for($this->resort)->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->forStay('2030-07-03', '2030-07-06')
            ->confirmed()
            ->create(['rooms_booked' => 1]);

        $booking = $this->booking();

        $this->move($booking, '2030-07-04', '2030-07-05');

        $this->assertSame(
            '2030-06-20',
            $booking->fresh()->check_in_date->toDateString(),
            'A move onto taken dates must not go through.'
        );
    }

    public function test_the_past_and_a_backwards_range_are_refused(): void
    {
        $booking = $this->booking();

        $this->move($booking, '2030-05-01', '2030-05-03')
            ->assertSessionHasErrors('check_in_date');

        $this->move($booking, '2030-07-05', '2030-07-03')
            ->assertSessionHasErrors('check_out_date');

        $this->assertSame('2030-06-20', $booking->fresh()->check_in_date->toDateString());
    }

    /* What the page offers ----------------------------------------------- */

    public function test_the_page_offers_the_move_while_waiting_and_not_after(): void
    {
        $pending = $this->booking();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $pending))
            ->assertOk()
            ->assertSee('Change my dates');

        $confirmed = $this->booking(['status' => BookingStatus::Confirmed]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $confirmed))
            ->assertOk()
            ->assertDontSee('Change my dates');
    }
}
