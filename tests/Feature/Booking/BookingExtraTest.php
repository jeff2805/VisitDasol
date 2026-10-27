<?php

namespace Tests\Feature\Booking;

use App\Enums\ExtraPriceUnit;
use App\Enums\ExtraStatus;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Resort;
use App\Models\ResortExtra;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kayak hire, a banana boat ride, island hopping.
 *
 * THREE RULES THESE TESTS EXIST TO PROTECT:
 *
 *   1. THE PRICE IS NEVER TAKEN FROM THE FORM. It is read from the
 *      resort's own listing and snapshotted, so a guest cannot ask for
 *      a one-peso banana boat and an owner cannot quietly reprice one
 *      that was already agreed.
 *
 *   2. NOTHING HERE REACHES THE BOOKING TOTAL. Extras are settled at
 *      the resort, so asking for one must change no subtotal, no
 *      discount, no deposit and no payment deadline.
 *
 *   3. A REQUEST IS NOT A PURCHASE. Whether a boat goes out depends on
 *      the weather and the boatman; the resort answers each one.
 */
class BookingExtraTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->resort = Resort::factory()->active()->create(['owner_id' => $this->owner->id]);

        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'bed_count' => 3,
            'price_per_night' => 2000,
            'total_rooms' => 4,
        ]);
    }

    private function extra(array $attributes = []): ResortExtra
    {
        // active is not fillable on purpose — switching an offering off
        // is its own action — so it is set after construction.
        $active = $attributes['active'] ?? true;
        unset($attributes['active']);

        $extra = new ResortExtra($attributes + [
            'name' => 'Banana boat ride',
            'price' => 350,
            'price_unit' => ExtraPriceUnit::PerPerson,
            'max_quantity' => 10,
        ]);

        $extra->resort()->associate($this->resort);
        $extra->active = $active;
        $extra->save();

        return $extra->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'room' => $this->room->id,
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

    private function book(array $overrides = []): Booking
    {
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return Booking::latest('id')->firstOrFail();
    }

    /* The owner's own list -------------------------------------------------- */

    public function test_an_owner_adds_something_their_resort_offers(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.extras.store'), [
                'name' => 'Kayak hire',
                'price' => 250,
                'price_unit' => ExtraPriceUnit::PerHour->value,
                'max_quantity' => 4,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('owner.extras.index'));

        $extra = ResortExtra::sole();

        $this->assertSame('Kayak hire', $extra->name);
        $this->assertSame(ExtraPriceUnit::PerHour, $extra->price_unit);
        $this->assertSame((int) $this->resort->id, (int) $extra->resort_id);
        $this->assertTrue($extra->active);
    }

    public function test_a_tourist_cannot_add_to_a_resorts_list(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('owner.extras.index'))->assertForbidden();

        $this->actingAs($this->tourist)
            ->post(route('owner.extras.store'), [
                'name' => 'Free boat', 'price' => 1,
                'price_unit' => 'per_person', 'max_quantity' => 1,
            ])->assertForbidden();

        $this->assertDatabaseCount('resort_extras', 0);
    }

    /** Changing an id in the URL reaches another resort's list. */
    public function test_an_owner_cannot_edit_another_resorts_offering(): void
    {
        $stranger = User::factory()->resortOwner()->create();
        $theirResort = Resort::factory()->active()->create(['owner_id' => $stranger->id]);

        $theirs = new ResortExtra([
            'name' => 'Their kayak', 'price' => 100,
            'price_unit' => ExtraPriceUnit::PerHour, 'max_quantity' => 2,
        ]);
        $theirs->resort()->associate($theirResort);
        $theirs->save();

        $this->actingAs($this->owner)
            ->get(route('owner.extras.edit', $theirs))->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('owner.extras.update', $theirs), [
                'name' => 'Hijacked', 'price' => 1,
                'price_unit' => 'per_hour', 'max_quantity' => 1,
            ])->assertForbidden();

        $this->assertSame('Their kayak', $theirs->refresh()->name);
    }

    public function test_two_offerings_cannot_share_a_name(): void
    {
        $this->extra(['name' => 'Kayak hire']);

        $this->actingAs($this->owner)
            ->post(route('owner.extras.store'), [
                'name' => 'Kayak hire', 'price' => 400,
                'price_unit' => 'per_hour', 'max_quantity' => 2,
            ])
            ->assertSessionHasErrors('name');
    }

    /** Hidden, never deleted: guests' requests name it. */
    public function test_an_offering_is_hidden_rather_than_deleted(): void
    {
        $extra = $this->extra();

        $this->assertFalse($this->owner->can('delete', $extra));

        $this->actingAs($this->owner)
            ->patch(route('owner.extras.status', $extra))
            ->assertSessionHasNoErrors();

        $this->assertFalse($extra->refresh()->active);
        $this->assertDatabaseHas('resort_extras', ['id' => $extra->id]);
    }

    /* Asking for one -------------------------------------------------------- */

    public function test_a_guest_asks_for_an_extra_and_the_price_is_snapshotted(): void
    {
        $extra = $this->extra(['price' => 350, 'price_unit' => ExtraPriceUnit::PerPerson]);

        $booking = $this->book(['extras' => [$extra->id => 4]]);

        $line = $booking->extras()->sole();

        $this->assertSame('Banana boat ride', $line->name);
        $this->assertSame('350.00', $line->unit_price);
        $this->assertSame(4, $line->quantity);
        $this->assertSame('1400.00', $line->amount);
        $this->assertSame(ExtraStatus::Requested, $line->status);
    }

    /**
     * THE RULE AT THE HEART OF THIS. Whatever the form posts, the price
     * comes from the resort's own listing.
     */
    public function test_the_form_cannot_dictate_the_price(): void
    {
        $extra = $this->extra(['price' => 350]);

        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload([
                'extras' => [$extra->id => 2],

                // Hopeful nonsense, and must be ignored.
                'extra_price' => '1.00',
                'extras_amount' => '1.00',
            ]))
            ->assertSessionHasNoErrors();

        $line = Booking::latest('id')->firstOrFail()->extras()->sole();

        $this->assertSame('350.00', $line->unit_price);
        $this->assertSame('700.00', $line->amount);
    }

    /** An owner raising a price must not change an existing quote. */
    public function test_repricing_later_does_not_change_what_was_quoted(): void
    {
        $extra = $this->extra(['price' => 350]);

        $booking = $this->book(['extras' => [$extra->id => 2]]);

        $this->actingAs($this->owner)
            ->put(route('owner.extras.update', $extra), [
                'name' => $extra->name, 'price' => 900,
                'price_unit' => $extra->price_unit->value, 'max_quantity' => 10,
            ])
            ->assertSessionHasNoErrors();

        $line = $booking->extras()->sole();

        $this->assertSame('350.00', $line->unit_price);
        $this->assertSame('700.00', $line->amount);
    }

    public function test_a_quantity_past_the_ceiling_is_brought_back_to_it(): void
    {
        $extra = $this->extra(['price' => 100, 'max_quantity' => 3]);

        $booking = $this->book(['extras' => [$extra->id => 99]]);

        $line = $booking->extras()->sole();

        $this->assertSame(3, $line->quantity);
        $this->assertSame('300.00', $line->amount);
    }

    /**
     * A guest should not lose a room over a kayak, so an offering that
     * is not this resort's — or has been switched off — is dropped
     * rather than refusing the whole booking.
     */
    public function test_an_offering_from_another_resort_is_simply_ignored(): void
    {
        $stranger = Resort::factory()->active()->create();

        $theirs = new ResortExtra([
            'name' => 'Their boat', 'price' => 500,
            'price_unit' => ExtraPriceUnit::PerBooking, 'max_quantity' => 1,
        ]);
        $theirs->resort()->associate($stranger);
        $theirs->save();

        $booking = $this->book(['extras' => [$theirs->id => 1]]);

        $this->assertCount(0, $booking->extras);
        $this->assertDatabaseCount('booking_extras', 0);
    }

    public function test_a_hidden_offering_cannot_be_asked_for(): void
    {
        $extra = $this->extra(['active' => false]);

        $booking = $this->book(['extras' => [$extra->id => 2]]);

        $this->assertCount(0, $booking->extras);
    }

    public function test_a_booking_with_no_extras_is_unaffected(): void
    {
        $this->extra();

        $booking = $this->book();

        $this->assertCount(0, $booking->extras);
    }

    /* The money stays where it was ------------------------------------------ */

    /**
     * THE OTHER RULE AT THE HEART OF THIS. Extras are settled at the
     * resort, so nothing about the reservation's money may move.
     */
    public function test_asking_for_extras_changes_no_booking_money(): void
    {
        $extra = $this->extra(['price' => 350]);

        $without = $this->book();

        // One active booking per guest, so the second needs another.
        $other = User::factory()->create();
        $this->actingAs($other)
            ->post(route('tourist.bookings.store'), $this->payload(['extras' => [$extra->id => 4]]))
            ->assertSessionHasNoErrors();

        $with = Booking::latest('id')->firstOrFail();

        $this->assertSame($without->subtotal_amount, $with->subtotal_amount);
        $this->assertSame($without->total_amount, $with->total_amount);
        $this->assertSame($without->discount_total, $with->discount_total);
        $this->assertSame($without->amount_due_now, $with->amount_due_now);

        // And the extra really was recorded, so this is not passing by
        // the extras never arriving.
        $this->assertSame('1400.00', $with->extras()->sole()->amount);
    }

    /* The resort's answer ----------------------------------------------------- */

    private function requested(): BookingExtra
    {
        $extra = $this->extra(['price' => 350]);

        return $this->book(['extras' => [$extra->id => 2]])->extras()->sole();
    }

    public function test_the_owner_confirms_a_request(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'confirmed'])
            ->assertSessionHasNoErrors();

        $line->refresh();

        $this->assertSame(ExtraStatus::Confirmed, $line->status);
        $this->assertSame((int) $this->owner->id, (int) $line->answered_by);
        $this->assertNotNull($line->answered_at);
    }

    /**
     * "No" on its own is the answer that generates a phone call. A
     * guest who planned a day around a banana boat needs to know
     * whether it is the weather, the boat or the date.
     */
    public function test_declining_requires_a_reason(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'declined'])
            ->assertSessionHasErrors('owner_note');

        $this->assertSame(ExtraStatus::Requested, $line->refresh()->status);
    }

    public function test_the_owner_declines_with_a_reason_the_guest_can_read(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->patch(route('owner.booking-extras.answer', $line), [
                'status' => 'declined',
                'owner_note' => 'The boat is out of the water for repairs that week.',
            ])
            ->assertSessionHasNoErrors();

        $line->refresh();

        $this->assertSame(ExtraStatus::Declined, $line->status);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $line->booking))
            ->assertOk()
            ->assertSee('out of the water for repairs');
    }

    public function test_a_tourist_cannot_answer_their_own_request(): void
    {
        $line = $this->requested();

        $this->actingAs($this->tourist)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(ExtraStatus::Requested, $line->refresh()->status);
    }

    public function test_another_owner_cannot_answer_it(): void
    {
        $line = $this->requested();
        $stranger = User::factory()->resortOwner()->create();

        $this->actingAs($stranger)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(ExtraStatus::Requested, $line->refresh()->status);
    }

    /** The status is a yes or a no. "Requested" is not an answer. */
    public function test_requested_cannot_be_posted_as_an_answer(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'requested'])
            ->assertSessionHasErrors('status');
    }

    public function test_answering_is_written_to_the_audit_trail(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->patch(route('owner.booking-extras.answer', $line), ['status' => 'confirmed'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'booking.extra_answered',
            'subject_type' => 'booking',
            'subject_id' => $line->booking_id,
            'user_id' => $this->owner->id,
        ]);
    }

    /* What each side sees ------------------------------------------------------ */

    public function test_the_guest_sees_what_they_asked_for_and_that_it_is_paid_at_the_resort(): void
    {
        $line = $this->requested();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $line->booking))
            ->assertOk()
            ->assertSee('Banana boat ride')
            ->assertSee('Waiting for the resort')
            ->assertSee('settled with the resort');
    }

    public function test_the_owner_sees_the_request_with_buttons_to_answer_it(): void
    {
        $line = $this->requested();

        $this->actingAs($this->owner)
            ->get(route('owner.bookings.show', $line->booking))
            ->assertOk()
            ->assertSee('Banana boat ride')
            ->assertSee('Yes, we can');
    }

    public function test_the_booking_form_offers_what_the_resort_has(): void
    {
        $this->extra(['name' => 'Kayak hire']);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertSee('Kayak hire')
            ->assertSee('not part of the total');
    }

    public function test_a_resort_with_nothing_on_offer_shows_no_section(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.create', [
                'room' => $this->room->id,
                'check_in' => '2030-07-10',
                'check_out' => '2030-07-12',
            ]))
            ->assertOk()
            ->assertDontSee('Anything else?');
    }
}
