<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');
        $this->mto = User::factory()->superAdmin()->create();
    }

    public function test_the_office_sees_reservations_from_every_resort(): void
    {
        $first = Booking::factory()->create();
        $second = Booking::factory()->create();

        $this->actingAs($this->mto)->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertSee($first->booking_reference)
            ->assertSee($second->booking_reference)
            ->assertSee('2 reservations found');
    }

    public function test_reservations_can_be_filtered_by_status_resort_and_guest(): void
    {
        $salt = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $bayside = Resort::factory()->active()->create(['name' => 'Bayside Retreat']);

        $confirmed = Booking::factory()->for(Room::factory()->for($salt))->confirmed()->create(['contact_name' => 'Bea Santos']);
        $pending = Booking::factory()->for(Room::factory()->for($bayside))->create(['contact_name' => 'Kevin Lim']);

        $this->actingAs($this->mto)->get(route('admin.bookings.index', ['status' => 'confirmed']))
            ->assertSee($confirmed->booking_reference)->assertDontSee($pending->booking_reference);

        $this->actingAs($this->mto)->get(route('admin.bookings.index', ['resort' => $bayside->id]))
            ->assertSee($pending->booking_reference)->assertDontSee($confirmed->booking_reference);

        $this->actingAs($this->mto)->get(route('admin.bookings.index', ['q' => 'Bea']))
            ->assertSee($confirmed->booking_reference)->assertDontSee($pending->booking_reference);

        $this->actingAs($this->mto)->get(route('admin.bookings.index', ['q' => $pending->booking_reference]))
            ->assertSee($pending->booking_reference)->assertDontSee($confirmed->booking_reference);
    }

    public function test_dates_can_mean_the_stay_or_when_the_request_was_made(): void
    {
        $julyStay = Booking::factory()->forStay('2030-07-10', '2030-07-13')->create();
        $julyStay->forceFill(['created_at' => '2030-06-01 10:00:00'])->save();

        $augustStay = Booking::factory()->forStay('2030-08-10', '2030-08-12')->create();
        $augustStay->forceFill(['created_at' => '2030-07-20 10:00:00'])->save();

        // By stay dates
        $this->actingAs($this->mto)->get(route('admin.bookings.index', ['from' => '2030-08-01', 'to' => '2030-08-31']))
            ->assertSee($augustStay->booking_reference)->assertDontSee($julyStay->booking_reference);

        // By when the request was made
        $this->actingAs($this->mto)->get(route('admin.bookings.index', [
            'basis' => 'requested', 'from' => '2030-07-01', 'to' => '2030-07-31',
        ]))->assertSee($augustStay->booking_reference)->assertDontSee($julyStay->booking_reference);
    }

    public function test_nonsense_filters_are_ignored_rather_than_breaking_the_page(): void
    {
        $booking = Booking::factory()->create();

        $this->actingAs($this->mto)->get(route('admin.bookings.index', [
            'status' => 'exploded',
            'resort' => "1 OR 1=1",
            'from' => 'yesterday',
            'basis' => 'whatever',
        ]))->assertOk()->assertSee($booking->booking_reference);
    }

    public function test_the_detail_page_shows_the_whole_history(): void
    {
        $booking = Booking::factory()->confirmed()->create(['contact_name' => 'Bea Santos']);
        $booking->forceFill([
            'special_requests' => 'Arriving late.',
            'confirmed_at' => now(),
        ])->save();

        $this->actingAs($this->mto)->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->booking_reference)
            ->assertSee('Bea Santos')
            ->assertSee('Arriving late.')
            ->assertSee('Confirmed by the resort')
            ->assertSee($booking->resort->name)
            ->assertSee($booking->resort->owner->full_name);
    }

    public function test_the_office_cannot_change_a_reservation(): void
    {
        $booking = Booking::factory()->create();

        // The screen offers no buttons, and the owner routes refuse the office.
        $this->actingAs($this->mto)->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee('there are no buttons here')
            ->assertDontSee('Mark completed');

        $this->actingAs($this->mto)->patch(route('owner.bookings.confirm', $booking))->assertForbidden();
        $this->actingAs($this->mto)->patch(route('tourist.bookings.cancel', $booking))->assertForbidden();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_owners_and_tourists_cannot_open_the_office_screens(): void
    {
        $booking = Booking::factory()->create();
        $owner = $booking->resort->owner;

        foreach ([$owner, $booking->tourist] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.bookings.index'))->assertForbidden();
            $this->actingAs($intruder)->get(route('admin.bookings.show', $booking))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('admin.bookings.index'))->assertRedirect(route('login'));
    }

    public function test_the_dashboard_counts_reservations(): void
    {
        Booking::factory()->count(2)->forStay('2030-08-01', '2030-08-03')->create();
        Booking::factory()->confirmed()->forStay('2030-07-10', '2030-07-12')->create();

        $response = $this->actingAs($this->mto)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total bookings')
            ->assertSee('Bookings by status')
            // "Today" is June 2030, so that is the year both charts open on.
            ->assertViewHas('year', 2030);

        $response->assertViewHas('metrics', fn (array $metrics) => collect($metrics)
            ->firstWhere('label', 'Total bookings')['total'] === 3);

        $response->assertViewHas('bookingMix', function (array $mix): bool {
            $byStatus = collect($mix['rows'])->keyBy('value');

            return $mix['total'] === 3
                && $byStatus[BookingStatus::Pending->value]['count'] === 2
                && $byStatus[BookingStatus::Confirmed->value]['count'] === 1;
        });
    }
}
