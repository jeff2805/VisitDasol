<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingPricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Child and PWD discounts, and the bed count that goes with them.
 *
 * A room is priced by the night, so a discount belonging to one guest
 * comes off that guest's share of the room bill. The arithmetic lives in
 * BookingPricer; what matters here is that the figure a guest is shown
 * is the figure that is stored, and that nobody is discounted twice.
 */
class GuestDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->tourist = User::factory()->create();
        $this->resort = Resort::factory()->active()->create();
    }

    private function room(array $attributes = []): Room
    {
        return Room::factory()->for($this->resort)->create($attributes + [
            'capacity' => 6,
            'bed_count' => 3,
            'price_per_night' => 1000,
            'total_rooms' => 4,
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
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Bea Santos',
            'contact_phone' => '09171234567',
            'origin' => 'pangasinan',
        ], $overrides);

        // The office's return needs a sex split that adds up to the party.
        $guests = (int) $payload['adults'] + (int) $payload['children'];
        $payload['guests_male'] ??= intdiv($guests, 2);
        $payload['guests_female'] ??= $guests - intdiv($guests, 2);

        return $payload;
    }

    private function book(Room $room, array $overrides = []): Booking
    {
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($room, $overrides))
            ->assertSessionHasNoErrors();

        return Booking::latest('id')->sole();
    }

    /* What is actually charged ---------------------------------------------- */

    public function test_a_room_with_no_policy_discounts_nothing(): void
    {
        $booking = $this->book($this->room());

        // 1000 x 2 nights x 1 room.
        $this->assertSame('2000.00', $booking->subtotal_amount);
        $this->assertSame('0.00', $booking->discount_total);
        $this->assertSame('2000.00', $booking->total_amount);
    }

    public function test_a_child_is_discounted_on_their_share_and_not_the_whole_room(): void
    {
        $room = $this->room();
        $room->forceFill(['child_discount_enabled' => true, 'child_discount_percent' => 50])->save();

        $booking = $this->book($room, ['adults' => 3, 'children' => 1]);

        /*
         * 2000 over four guests is 500 each. Half of one share is 250 —
         * not half of the room, which would be 1000 and would hand three
         * adults a discount none of them is entitled to.
         */
        $this->assertSame('2000.00', $booking->subtotal_amount);
        $this->assertSame('250.00', $booking->child_discount_amount);
        $this->assertSame('250.00', $booking->discount_total);
        $this->assertSame('1750.00', $booking->total_amount);
        $this->assertSame(50, $booking->child_discount_percent);
    }

    public function test_a_pwd_guest_is_discounted_on_their_share(): void
    {
        $room = $this->room();
        $room->forceFill(['pwd_discount_enabled' => true, 'pwd_discount_percent' => 20])->save();

        $booking = $this->book($room, ['adults' => 2, 'pwd_guests' => 1]);

        // 2000 over two guests is 1000 each; 20% of one share is 200.
        $this->assertSame('200.00', $booking->pwd_discount_amount);
        $this->assertSame('0.00', $booking->child_discount_amount);
        $this->assertSame('1800.00', $booking->total_amount);
        $this->assertSame(20, $booking->pwd_discount_percent);
    }

    public function test_both_discounts_are_charged_against_different_guests(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 50,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 20,
        ])->save();

        // Four guests: three adults (one with a PWD ID) and one child.
        $booking = $this->book($room, ['adults' => 3, 'children' => 1, 'pwd_guests' => 1]);

        // 500 a share. Half of one child's, a fifth of one adult's.
        $this->assertSame('250.00', $booking->child_discount_amount);
        $this->assertSame('100.00', $booking->pwd_discount_amount);
        $this->assertSame('350.00', $booking->discount_total);
        $this->assertSame('1650.00', $booking->total_amount);
    }

    public function test_a_guest_who_is_both_a_child_and_a_pwd_is_discounted_once(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 50,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 20,
        ])->save();

        /*
         * One adult and one child, and the one PWD ID in the party must be
         * the child's — there is nobody else it can belong to once the
         * adult is accounted for. The child is worth 50% or 20%, never both,
         * and the kinder of the two is the one that counts.
         */
        $booking = $this->book($room, ['adults' => 1, 'children' => 1, 'pwd_guests' => 2]);

        // 1000 a share. One adult at 20%, one child at 50% — not 70%.
        $this->assertSame('500.00', $booking->child_discount_amount);
        $this->assertSame('200.00', $booking->pwd_discount_amount);
        $this->assertSame('700.00', $booking->discount_total);
        $this->assertSame('1300.00', $booking->total_amount);
    }

    public function test_the_kinder_rate_wins_when_the_pwd_rate_is_the_higher_one(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 10,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 40,
        ])->save();

        /*
         * One adult, two children, two PWD IDs. One ID belongs to the
         * adult, so the other must belong to a child — and that child is
         * worth more as a PWD (40%) than as a child (10%). The remaining
         * child, who has no ID, keeps the child rate.
         */
        $booking = $this->book($room, ['adults' => 1, 'children' => 2, 'pwd_guests' => 2]);

        // 2000 over three is 666.66 a share, which divides badly on purpose.
        // One share at 10%; two shares at 40%.
        $this->assertSame('66.66', $booking->child_discount_amount);
        $this->assertSame('533.33', $booking->pwd_discount_amount);
        $this->assertSame('599.99', $booking->discount_total);
        $this->assertSame('1400.01', $booking->total_amount);
    }

    public function test_a_discount_never_exceeds_the_room_bill(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 100,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 100,
        ])->save();

        $booking = $this->book($room, ['adults' => 2, 'children' => 2, 'pwd_guests' => 2]);

        $this->assertSame('2000.00', $booking->discount_total);
        $this->assertSame('0.00', $booking->total_amount);
    }

    public function test_a_policy_that_is_switched_off_discounts_nothing(): void
    {
        $room = $this->room();

        // A percentage left behind by a policy the owner has since
        // switched off must not quietly keep applying.
        $room->forceFill([
            'child_discount_enabled' => false,
            'child_discount_percent' => 50,
            'pwd_discount_enabled' => false,
            'pwd_discount_percent' => 20,
        ])->save();

        $booking = $this->book($room, ['adults' => 2, 'children' => 2, 'pwd_guests' => 1]);

        $this->assertSame('0.00', $booking->discount_total);
        $this->assertSame('2000.00', $booking->total_amount);
    }

    /* The counts themselves -------------------------------------------------- */

    public function test_the_party_is_adults_plus_children_and_pwd_guests_are_not_added_again(): void
    {
        $booking = $this->book($this->room(), [
            'adults' => 2,
            'children' => 2,
            'pwd_guests' => 2,
        ]);

        $this->assertSame(4, $booking->guests);
        $this->assertSame(2, $booking->adults);
        $this->assertSame(2, $booking->children);
        $this->assertSame(2, $booking->pwd_guests);
    }

    public function test_more_pwd_guests_than_people_is_refused(): void
    {
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($this->room(), [
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 3,
                'guests_male' => 1,
                'guests_female' => 1,
            ]))
            ->assertSessionHasErrors('pwd_guests');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_beds_the_party_gets_are_recorded(): void
    {
        $booking = $this->book(
            $this->room(['bed_count' => 3]),
            ['adults' => 4, 'rooms_booked' => 2]
        );

        // Three beds in each of the two rooms taken.
        $this->assertSame(6, $booking->beds);
    }

    /* What the pages say ------------------------------------------------------ */

    public function test_the_booking_form_shows_the_rooms_policy(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 30,
            'child_discount_min_age' => 2,
            'child_discount_max_age' => 11,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 20,
        ])->save();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertSee('ages 2 to 11')
            ->assertSee('data-child-percent="30"', false)
            ->assertSee('data-pwd-percent="20"', false)
            ->assertSee('PWD guests');
    }

    public function test_the_reservation_page_breaks_the_discount_down(): void
    {
        $room = $this->room();
        $room->forceFill(['pwd_discount_enabled' => true, 'pwd_discount_percent' => 20])->save();

        $booking = $this->book($room, ['adults' => 2, 'pwd_guests' => 1]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('PWD discount')
            ->assertSee('₱200.00')
            ->assertSee('₱1,800.00')
            ->assertSee('1 with a PWD ID');
    }

    public function test_a_reservation_with_no_discount_shows_no_discount_lines(): void
    {
        $booking = $this->book($this->room());

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('PWD discount')
            ->assertDontSee('Child discount');
    }

    public function test_the_resort_page_advertises_the_policy(): void
    {
        $room = $this->room();
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 25,
            'child_discount_min_age' => 0,
            'child_discount_max_age' => 10,
        ])->save();

        $this->get(route('resorts.show', $this->resort))
            ->assertOk()
            ->assertSee('25% off children aged 10 and under');
    }

    /* The sums themselves ----------------------------------------------------- */

    public function test_the_parts_always_add_up_to_the_whole(): void
    {
        $pricer = app(BookingPricer::class);

        $room = $this->room(['price_per_night' => 1333]);
        $room->forceFill([
            'child_discount_enabled' => true,
            'child_discount_percent' => 33,
            'pwd_discount_enabled' => true,
            'pwd_discount_percent' => 17,
        ])->save();

        // An awkward rate over an awkward party, where every share
        // divides badly and rounding has somewhere to go wrong.
        foreach ([1, 3, 7] as $nights) {
            foreach ([[3, 2, 1], [1, 5, 4], [6, 0, 6]] as [$adults, $children, $pwd]) {
                $price = $pricer->quote($room, $nights, 2, $adults, $children, $pwd);

                $this->assertSame(
                    $price->discountTotal,
                    number_format(
                        (float) $price->childDiscount + (float) $price->pwdDiscount,
                        2, '.', ''
                    ),
                    'The two discount lines must add up to the discount total.'
                );

                $this->assertSame(
                    $price->total,
                    number_format(
                        (float) $price->subtotal - (float) $price->discountTotal,
                        2, '.', ''
                    ),
                    'The total must be the subtotal less the discounts.'
                );

                $this->assertLessThanOrEqual(
                    (float) $price->subtotal,
                    (float) $price->discountTotal,
                    'A discount must never exceed the room bill.'
                );
            }
        }
    }
}
