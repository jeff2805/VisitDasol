<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deleting a room type from the owner's Room Types page.
 *
 * The rules this holds, in the order they bite:
 *
 *   - a room nobody has booked goes;
 *   - a room with ANY reservation against it stays, so no booking row is
 *     ever orphaned and no history is lost — the owner stops bookings on
 *     it instead;
 *   - one owner never reaches another's rooms, whatever id is in the URL.
 *
 * bookings.room_id is restrictOnDelete at the database, which is the last
 * line rather than the first: the policy refuses before the query runs, so
 * an owner gets an explanation and not a constraint violation.
 */
class RoomDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
    }

    private function room(array $state = []): Room
    {
        return Room::factory()->for($this->resort)->create($state + [
            'capacity' => 4,
            'total_rooms' => 2,
            'price_per_night' => 1500,
        ]);
    }

    private function booking(Room $room, BookingStatus $status, string $in, string $out): Booking
    {
        return Booking::factory()->for($room)->forStay($in, $out)->withStatus($status)->create();
    }

    private function remove(Room $room)
    {
        return $this->actingAs($this->owner)
            ->delete(route('owner.rooms.destroy', $room));
    }

    /* The happy path -------------------------------------------------------- */

    public function test_a_room_with_no_bookings_is_deleted(): void
    {
        $room = $this->room(['name' => 'Seaview Cottage']);

        $this->remove($room)
            ->assertRedirect(route('owner.rooms.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    /** The message names the room, so the owner knows which one went. */
    public function test_the_success_message_names_the_room(): void
    {
        $room = $this->room(['name' => 'Seaview Cottage']);

        $this->remove($room);

        $this->assertStringContainsString('Seaview Cottage', (string) session('success'));
    }

    /** Its photographs go with it, rows and files both. */
    public function test_the_rooms_photographs_are_cleaned_up(): void
    {
        $public = Storage::fake('public');

        $room = $this->room();

        // Created through the relation: RoomImage has no factory, and
        // adding one for a single test would be a fixture nothing else
        // needs.
        $image = $room->images()->create(['path' => 'rooms/1/photo.jpg', 'sort_order' => 0]);
        Storage::disk('public')->put('rooms/1/photo.jpg', 'not really a jpeg');

        $this->remove($room)->assertRedirect();

        $this->assertDatabaseMissing('room_images', ['id' => $image->id]);
        $public->assertMissing('rooms/1/photo.jpg');
    }

    /** It is written down, because deleting inventory is worth a record. */
    public function test_the_deletion_is_logged(): void
    {
        $room = $this->room(['name' => 'Seaview Cottage']);

        $this->remove($room);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'room.deleted',
            'user_id' => $this->owner->id,
        ]);
    }

    /* Reservations stop it --------------------------------------------------- */

    /**
     * THE ONE THAT MATTERS MOST. A confirmed stay still ahead is a guest
     * with a room booked; deleting it would strand them.
     */
    public function test_a_room_with_an_upcoming_confirmed_stay_is_not_deleted(): void
    {
        $room = $this->room();
        $booking = $this->booking($room, BookingStatus::Confirmed, '2030-07-10', '2030-07-13');

        $this->remove($room)->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'room_id' => $room->id]);
    }

    /** A request nobody has answered holds the room too. */
    public function test_a_room_with_a_pending_request_is_not_deleted(): void
    {
        $room = $this->room();
        $this->booking($room, BookingStatus::Pending, '2030-07-10', '2030-07-13');

        $this->remove($room)->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    /**
     * HISTORY IS NOT LOST. A finished stay keeps its room, its dates and
     * its money — the reports and the guest's own reservation page read
     * from these rows, and a deleted room would take them with it.
     */
    public function test_a_room_with_a_completed_stay_keeps_it(): void
    {
        $room = $this->room();
        $booking = $this->booking($room, BookingStatus::Completed, '2030-04-10', '2030-04-13');

        $this->remove($room)->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'room_id' => $room->id,
            'total_amount' => $booking->total_amount,
        ]);
    }

    /** The same for one that was declined or called off. */
    public function test_a_room_with_a_cancelled_or_rejected_stay_keeps_it(): void
    {
        foreach ([BookingStatus::Cancelled, BookingStatus::Rejected] as $status) {
            $room = $this->room(['name' => 'Room '.$status->value]);
            $this->booking($room, $status, '2030-04-10', '2030-04-13');

            $this->remove($room)->assertRedirect()->assertSessionHas('error');

            $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        }
    }

    /**
     * The owner is told to switch it off instead, which is the thing they
     * actually want: no new bookings, history intact.
     */
    public function test_stopping_bookings_is_available_where_deleting_is_not(): void
    {
        $room = $this->room();
        $this->booking($room, BookingStatus::Confirmed, '2030-07-10', '2030-07-13');

        $this->actingAs($this->owner)
            ->patch(route('owner.rooms.status', $room))
            ->assertRedirect();

        $this->assertFalse($room->fresh()->isActive());
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    /* Authorization ---------------------------------------------------------- */

    public function test_another_owner_cannot_delete_this_room(): void
    {
        $room = $this->room();

        $stranger = User::factory()->resortOwner()->create();
        Resort::factory()->for($stranger, 'owner')->active()->create();

        $this->actingAs($stranger)
            ->delete(route('owner.rooms.destroy', $room))
            ->assertForbidden();

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_a_tourist_cannot_delete_a_room(): void
    {
        $room = $this->room();

        $this->actingAs(User::factory()->create())
            ->delete(route('owner.rooms.destroy', $room))
            ->assertForbidden();

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_a_signed_out_visitor_cannot_delete_a_room(): void
    {
        $room = $this->room();

        $this->delete(route('owner.rooms.destroy', $room))->assertRedirect(route('login'));

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    /* The button on the page -------------------------------------------------- */

    /** A deletable room gets a real DELETE form, not a dead span. */
    public function test_the_page_renders_a_working_delete_form(): void
    {
        $room = $this->room(['name' => 'Seaview Cottage']);

        $html = $this->actingAs($this->owner)
            ->get(route('owner.rooms.index'))
            ->assertOk()
            ->getContent();

        preg_match(
            '/<form[^>]*action="'.preg_quote(route('owner.rooms.destroy', $room), '/').'".*?<\/form>/s',
            $html,
            $m
        );

        $form = $m[0] ?? '';

        $this->assertNotSame('', $form, 'The delete button is not a form.');
        $this->assertStringContainsString('method="POST"', $form);
        $this->assertStringContainsString('name="_token"', $form, 'No CSRF token.');
        $this->assertStringContainsString('value="DELETE"', $form, 'No method override.');
        $this->assertStringContainsString('type="submit"', $form, 'Nothing to press.');
        $this->assertStringContainsString('data-confirm', $form, 'No confirmation before an irreversible act.');
    }

    /**
     * A room with reservations shows a control that is visibly disabled
     * AND says why.
     *
     * THIS IS THE BUG THE WHOLE TASK WAS ABOUT. It was a <span> dressed as
     * a button, with the reason in a title attribute. It looked exactly
     * like the live Delete button, clicking it did nothing, and a title
     * needs a hover — so on a phone there was no explanation at all. The
     * owner pressed Delete, nothing happened, and nothing told them why.
     */
    public function test_the_page_disables_delete_where_it_would_be_refused(): void
    {
        $room = $this->room(['name' => 'Booked Cottage']);
        $this->booking($room, BookingStatus::Confirmed, '2030-07-10', '2030-07-13');

        $html = $this->actingAs($this->owner)
            ->get(route('owner.rooms.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'action="'.route('owner.rooms.destroy', $room).'"',
            $html,
            'A room that cannot be deleted must not offer a live delete form.'
        );

        // A real disabled button, which the browser and a screen reader
        // both report as disabled — not a span that merely looks like one.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\bdisabled\b[^>]*>\s*Delete\s*<\/button>/s',
            $html,
            'The dead control must be a disabled <button>, not a <span>.'
        );

        // And the reason is ON THE PAGE, not in a tooltip a touch screen
        // can never show.
        $this->assertStringContainsString('cannot be', $html);
        $this->assertStringContainsString('booking history', $html);
        $this->assertStringContainsString('id="no-delete-'.$room->id.'"', $html);
        $this->assertStringContainsString('Stop bookings', $html);

        // The count is named, so the owner can go and look at them.
        $this->assertStringContainsString('1 reservation', $html);
    }
}
