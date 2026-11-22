<?php

namespace Tests\Feature\Owner;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The room type name is chosen from a list, not typed.
 *
 * It was a free text box, and the names that came out of it were
 * "ROOM1", "Room2", "family" and — twice — the resort's own name. A
 * guest comparing stays then had to work out whether one resort's
 * "ROOM1" was better or worse than another's "family".
 *
 * The case worth testing hardest is the one a menu breaks: rooms named
 * before the list existed. An owner opening such a room to change its
 * price must not be refused, and must not have it renamed out from
 * under them by a menu that cannot show what is already saved.
 */
class RoomNameChoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Deluxe',
            'room_type' => 'premium',
            'capacity' => 2,
            'bed_count' => 2,
            'price_per_night' => 2500,
            'total_rooms' => 4,
        ], $overrides);
    }

    /* The menu --------------------------------------------------------- */

    public function test_the_form_offers_every_listed_name_as_a_menu(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('owner.rooms.create'))
            ->assertOk();

        foreach (Room::NAMES as $name) {
            $response->assertSee('<option value="'.$name.'"', escape: false);
        }

        // Not a text box any more.
        $response->assertDontSee('<input type="text" name="name"', escape: false);
    }

    public function test_the_names_are_the_ones_asked_for(): void
    {
        $this->assertSame(
            [
                'Single Room',
                'Double Room',
                'Family Room',
                'Deluxe',
                'Standard Room',
                'Cottage',
            ],
            Room::NAMES,
        );
    }

    /* What the rule accepts -------------------------------------------- */

    public function test_a_listed_name_is_accepted(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.rooms.store'), $this->payload(['name' => 'Family Room']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rooms', [
            'resort_id' => $this->resort->id,
            'name' => 'Family Room',
        ]);
    }

    /**
     * A menu is a suggestion to anything that posts the form directly,
     * so the list has to be enforced behind it as well.
     */
    public function test_a_name_off_the_list_is_refused_even_when_posted_directly(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.rooms.store'), $this->payload(['name' => 'ROOM1']))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('rooms', ['name' => 'ROOM1']);
    }

    /* Rooms that predate the list -------------------------------------- */

    /**
     * The whole point of carrying the saved name into the menu. Without
     * it, every room named before this change became uneditable.
     */
    public function test_an_old_name_can_still_be_saved_unchanged(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'ROOM1']);

        $this->actingAs($this->owner)
            ->put(route('owner.rooms.update', $room), $this->payload([
                'name' => 'ROOM1',
                'price_per_night' => 3100,
            ]))
            ->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertSame('ROOM1', $room->name);
        $this->assertSame('3100.00', $room->price_per_night);
    }

    public function test_the_edit_form_shows_the_old_name_as_the_chosen_option(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'ROOM1']);

        $this->actingAs($this->owner)
            ->get(route('owner.rooms.edit', $room))
            ->assertOk()
            ->assertSee('<option value="ROOM1" selected>ROOM1</option>', escape: false);
    }

    /**
     * Grandfathering is per room. One room's odd name must not become a
     * valid choice for a different room.
     */
    public function test_one_rooms_old_name_is_not_offered_to_another_room(): void
    {
        Room::factory()->for($this->resort)->create(['name' => 'ROOM1']);
        $other = Room::factory()->for($this->resort)->create(['name' => 'Deluxe']);

        $this->actingAs($this->owner)
            ->put(route('owner.rooms.update', $other), $this->payload(['name' => 'ROOM1']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Deluxe', $other->fresh()->name);
    }

    public function test_an_old_name_can_be_traded_for_a_listed_one(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'ROOM1']);

        $this->actingAs($this->owner)
            ->put(route('owner.rooms.update', $room), $this->payload(['name' => 'Single Room']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Single Room', $room->fresh()->name);
    }

    /* Still one of each per resort ------------------------------------- */

    /**
     * The uniqueness rule predates this change, and with a fixed list it
     * bites harder: a resort can list each name once, so the length of
     * Room::NAMES is now also the most room types a resort may have.
     * Worth pinning, because it is the practical limit the list imposes.
     */
    public function test_a_resort_cannot_list_the_same_name_twice(): void
    {
        Room::factory()->for($this->resort)->create(['name' => 'Deluxe']);

        $this->actingAs($this->owner)
            ->post(route('owner.rooms.store'), $this->payload(['name' => 'Deluxe']))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Room::where('resort_id', $this->resort->id)->where('name', 'Deluxe')->count());
    }

    public function test_two_resorts_may_both_use_the_same_name(): void
    {
        Room::factory()->for($this->resort)->create(['name' => 'Deluxe']);

        $otherOwner = User::factory()->resortOwner()->create();
        Resort::factory()->for($otherOwner, 'owner')->active()->create();

        $this->actingAs($otherOwner)
            ->post(route('owner.rooms.store'), $this->payload(['name' => 'Deluxe']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Room::where('name', 'Deluxe')->count());
    }
}
