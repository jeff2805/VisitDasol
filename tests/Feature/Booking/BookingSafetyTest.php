<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The parts of the engine that protect the resort and the guest:
 * stale forms, references, and what ends up in the audit trail.
 */
class BookingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');
        $this->tourist = User::factory()->create();
        $resort = Resort::factory()->active()->create();
        $this->room = Room::factory()->for($resort)->create([
            'total_rooms' => 1,
            'capacity' => 2,
            'price_per_night' => 2500,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'room' => $this->room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-13',
            'guests' => 2,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'origin' => 'pangasinan',
        ], $overrides);

        // The office's return requires a sex split that adds up to the party,
        // so it follows whatever party size the test asked for.
        if (! array_key_exists('guests_male', $payload) && ! array_key_exists('guests_female', $payload)) {
            $guests = max(0, (int) $payload['guests']);

            $payload['guests_male'] = intdiv($guests, 2);
            $payload['guests_female'] = $guests - intdiv($guests, 2);
        }

        return $payload;
    }

    public function test_a_form_opened_while_the_room_was_free_is_still_re_checked_on_submit(): void
    {
        // The guest opens the booking form: one unit is free.
        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-13',
                'guests' => 2,
            ]))
            ->assertOk()
            ->assertSee('1 room');

        // Someone else takes the last unit in the meantime.
        Booking::factory()->for($this->room)->forStay('2030-07-10', '2030-07-13')->confirmed()->create();

        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Booking::count(), 'The stale form must not create a second booking.');
    }

    public function test_a_listing_taken_offline_after_the_form_was_opened_is_refused(): void
    {
        $this->room->resort->forceFill(['status' => \App\Enums\ResortStatus::Inactive])->save();

        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload())
            ->assertSessionHas('error');

        $this->assertSame(0, Booking::count());
    }

    public function test_references_are_unique_and_use_the_arrival_date(): void
    {
        $service = app(BookingService::class);

        $first = $service->create($this->tourist, $this->room->id, $this->payload());

        $room = Room::factory()->for(Resort::factory()->active())->create(['capacity' => 4]);
        $second = $service->create(User::factory()->create(), $room->id, $this->payload([
            'room' => $room->id,
        ]));

        foreach ([$first, $second] as $booking) {
            $this->assertMatchesRegularExpression('/^VD-20300710-[A-HJ-NP-Z2-9]{5}$/', $booking->booking_reference);
        }

        $this->assertNotSame($first->booking_reference, $second->booking_reference);

        // Nothing in a reference can be confused when read aloud.
        $this->assertDoesNotMatchRegularExpression('/[01OI]/', substr($first->booking_reference, 12));
    }

    public function test_the_booking_is_written_to_the_audit_trail(): void
    {
        $booking = app(BookingService::class)->create($this->tourist, $this->room->id, $this->payload());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'booking.created',
            'user_id' => $this->tourist->id,
            'subject_type' => 'booking',
            'subject_id' => $booking->id,
        ]);
    }

    public function test_the_total_is_worked_out_in_centavos(): void
    {
        $service = app(BookingService::class);

        // 1999.99 x 3 nights x 2 rooms: adding floats would drift.
        $this->assertSame('11999.94', $service->total('1999.99', 3, 2));
        $this->assertSame('2500.00', $service->total('2500.00', 1, 1));
        $this->assertSame('0.00', $service->total('2500.00', 0, 1));
    }

    public function test_the_engine_refuses_dates_in_the_past_even_when_called_directly(): void
    {
        $this->expectException(BookingException::class);

        app(BookingService::class)->create($this->tourist, $this->room->id, $this->payload([
            'check_in_date' => '2030-05-01',
            'check_out_date' => '2030-05-03',
        ]));
    }

    public function test_a_booking_holds_the_room_for_the_owners_inventory_count(): void
    {
        app(BookingService::class)->create($this->tourist, $this->room->id, $this->payload());

        $owner = $this->room->resort->owner;

        $this->actingAs($owner)->put(route('owner.rooms.update', $this->room), [
            'name' => $this->room->name,
            'room_type' => $this->room->room_type->value,
            'capacity' => $this->room->capacity,
            'price_per_night' => 2500,
            'total_rooms' => 0,
        ])->assertSessionHasErrors('total_rooms');
    }

    public function test_the_demo_data_contains_bookings_the_engine_would_accept(): void
    {
        config(['visitdasol.demo.enabled' => true, 'visitdasol.demo.password' => 'DemoPass2030']);
        config(['visitdasol.mto_admin.email' => 'mto@example.test', 'visitdasol.mto_admin.password' => 'Strong-Pass-2030']);

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $bookings = Booking::with('room')->get();
        $this->assertGreaterThanOrEqual(5, $bookings->count());
        $this->assertTrue($bookings->contains(fn (Booking $b) => $b->status === BookingStatus::Confirmed));
        $this->assertTrue($bookings->contains(fn (Booking $b) => $b->status === BookingStatus::Pending));
        $this->assertTrue($bookings->contains(fn (Booking $b) => $b->status === BookingStatus::Completed));
        $this->assertTrue($bookings->contains(fn (Booking $b) => $b->status === BookingStatus::Cancelled));

        // Every stay fits within what its room type can hold.
        foreach ($bookings as $booking) {
            $this->assertLessThanOrEqual(
                $booking->room->capacity * $booking->rooms_booked,
                $booking->guests,
                "Demo booking {$booking->booking_reference} has more guests than the room takes."
            );
            // The room charge is rate x nights x rooms. What the guest
            // pays is that, less whatever the resort's child and PWD
            // policies took off, so the two are compared separately.
            $this->assertSame(
                app(BookingService::class)->total($booking->unit_price, $booking->nights, $booking->rooms_booked),
                $booking->subtotal_amount,
                "Demo booking {$booking->booking_reference} has a subtotal that is not rate x nights x rooms."
            );

            $this->assertSame(
                number_format(
                    (float) $booking->subtotal_amount - (float) $booking->discount_total,
                    2, '.', ''
                ),
                $booking->total_amount,
                "Demo booking {$booking->booking_reference} does not total its subtotal less its discounts."
            );
        }
    }
}
