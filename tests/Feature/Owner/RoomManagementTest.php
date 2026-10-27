<?php

namespace Tests\Feature\Owner;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomManagementTest extends TestCase
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
            'name' => 'Seaview Deluxe',
            'room_type' => 'premium',
            'description' => 'Two single beds with a view of the bay.',
            'capacity' => 2,
            'bed_count' => 2,
            'price_per_night' => 2500,
            'total_rooms' => 4,
        ], $overrides);
    }

    public function test_an_owner_must_register_a_resort_before_adding_rooms(): void
    {
        $newOwner = User::factory()->resortOwner()->create();

        $this->actingAs($newOwner)->get(route('owner.rooms.index'))
            ->assertRedirect(route('owner.resort.create'));
    }

    public function test_a_room_type_can_be_added(): void
    {
        $this->actingAs($this->owner)->get(route('owner.rooms.create'))->assertOk()->assertSee('Add a room type');

        $this->actingAs($this->owner)->post(route('owner.rooms.store'), $this->payload([
            // Must be ignored: the resort comes from the signed-in owner.
            'resort_id' => Resort::factory()->create()->id,
            'status' => 'inactive',
        ]))->assertRedirect(route('owner.rooms.index'));

        $room = Room::sole();
        $this->assertSame($this->resort->id, $room->resort_id);
        $this->assertSame(RoomStatus::Active, $room->status);
        $this->assertSame(RoomType::Premium, $room->room_type);
        $this->assertSame('2500.00', $room->price_per_night);
        $this->assertSame(2, $room->bed_count);
        $this->assertDatabaseHas('activity_logs', ['action' => 'room.created', 'subject_id' => $room->id]);
    }

    public function test_room_validation(): void
    {
        $cases = [
            ['name' => ''],
            ['room_type' => 'penthouse'],
            ['capacity' => 0],
            ['capacity' => 99],
            ['bed_count' => 0],
            ['bed_count' => 99],
            ['price_per_night' => 0],
            ['total_rooms' => 0],
        ];

        foreach ($cases as $override) {
            $this->actingAs($this->owner)
                ->post(route('owner.rooms.store'), $this->payload($override))
                ->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertSame(0, Room::count());
    }

    /* Discount policy --------------------------------------------------------- */

    public function test_the_room_form_offers_beds_and_both_discounts(): void
    {
        $this->actingAs($this->owner)->get(route('owner.rooms.create'))
            ->assertOk()
            ->assertSee('Beds per room')
            ->assertSee('Give children a discount')
            ->assertSee('Give guests with a disability a discount')
            ->assertSee('name="bed_count"', false)
            ->assertSee('name="child_discount_percent"', false)
            ->assertSee('name="child_discount_min_age"', false)
            ->assertSee('name="pwd_discount_percent"', false);
    }

    public function test_the_room_list_shows_each_rooms_discount(): void
    {
        Room::factory()->for($this->resort)->childDiscount(40, 0, 10)->pwdDiscount(20)
            ->create(['name' => 'Family Cottage', 'bed_count' => 3]);

        $this->actingAs($this->owner)->get(route('owner.rooms.index'))
            ->assertOk()
            ->assertSee('3 beds')
            ->assertSee('40% children aged 10 and under')
            ->assertSee('20% PWD');
    }

    public function test_an_owner_can_set_a_child_and_pwd_discount(): void
    {
        $this->actingAs($this->owner)->post(route('owner.rooms.store'), $this->payload([
            'child_discount_enabled' => '1',
            'child_discount_percent' => 40,
            'child_discount_min_age' => 2,
            'child_discount_max_age' => 11,
            'pwd_discount_enabled' => '1',
            'pwd_discount_percent' => 20,
        ]))->assertSessionHasNoErrors();

        $room = Room::sole();
        $this->assertTrue($room->child_discount_enabled);
        $this->assertSame(40, $room->child_discount_percent);
        $this->assertSame(2, $room->child_discount_min_age);
        $this->assertSame(11, $room->child_discount_max_age);
        $this->assertTrue($room->pwd_discount_enabled);
        $this->assertSame(20, $room->pwd_discount_percent);
        $this->assertSame('ages 2 to 11', $room->childAgeRange());
    }

    public function test_a_discount_that_is_switched_on_needs_a_figure(): void
    {
        $cases = [
            ['child_discount_percent' => 'child_discount_percent'],
            ['child_discount_min_age' => 'child_discount_min_age'],
            ['child_discount_max_age' => 'child_discount_max_age'],
        ];

        foreach ($cases as $case) {
            $field = reset($case);

            $this->actingAs($this->owner)
                ->post(route('owner.rooms.store'), $this->payload([
                    'child_discount_enabled' => '1',
                    'child_discount_percent' => 30,
                    'child_discount_min_age' => 0,
                    'child_discount_max_age' => 12,
                    $field => '',
                ]))
                ->assertSessionHasErrors($field);
        }

        // An oldest age younger than the youngest is nonsense.
        $this->actingAs($this->owner)
            ->post(route('owner.rooms.store'), $this->payload([
                'child_discount_enabled' => '1',
                'child_discount_percent' => 30,
                'child_discount_min_age' => 10,
                'child_discount_max_age' => 4,
            ]))
            ->assertSessionHasErrors('child_discount_max_age');

        $this->assertSame(0, Room::count());
    }

    public function test_a_discount_left_switched_off_needs_no_figure_and_stores_none(): void
    {
        // A percentage sent alongside an unticked box must not take effect:
        // what the owner saw on the form is what the room has to end up with.
        $this->actingAs($this->owner)->post(route('owner.rooms.store'), $this->payload([
            'child_discount_percent' => 90,
            'pwd_discount_percent' => 90,
        ]))->assertSessionHasNoErrors();

        $room = Room::sole();
        $this->assertFalse($room->child_discount_enabled);
        $this->assertSame(0, $room->child_discount_percent);
        $this->assertFalse($room->pwd_discount_enabled);
        $this->assertSame(0, $room->pwd_discount_percent);
        $this->assertFalse($room->hasChildDiscount());
        $this->assertFalse($room->hasPwdDiscount());
    }

    public function test_switching_a_discount_off_clears_the_figure_it_had(): void
    {
        $room = Room::factory()->for($this->resort)->childDiscount(50)->pwdDiscount(20)
            ->create(['name' => 'Seaview Deluxe']);

        $this->actingAs($this->owner)
            ->put(route('owner.rooms.update', $room), $this->payload())
            ->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertFalse($room->child_discount_enabled);
        $this->assertSame(0, $room->child_discount_percent);
        $this->assertFalse($room->pwd_discount_enabled);
        $this->assertSame(0, $room->pwd_discount_percent);
    }

    public function test_room_names_must_be_unique_within_the_resort_only(): void
    {
        Room::factory()->for($this->resort)->create(['name' => 'Seaview Deluxe']);

        $this->actingAs($this->owner)->post(route('owner.rooms.store'), $this->payload())
            ->assertSessionHasErrors('name');

        // Another resort may use the same name.
        $otherOwner = User::factory()->resortOwner()->create();
        Resort::factory()->for($otherOwner, 'owner')->create();
        $this->actingAs($otherOwner)->post(route('owner.rooms.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Room::where('name', 'Seaview Deluxe')->count());
    }

    public function test_a_room_type_can_be_edited(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'Seaview Deluxe']);

        $this->actingAs($this->owner)->put(route('owner.rooms.update', $room), $this->payload([
            'name' => 'Seaview Deluxe (renovated)',
            'price_per_night' => 3200,
        ]))->assertRedirect(route('owner.rooms.index'));

        $room->refresh();
        $this->assertSame('Seaview Deluxe (renovated)', $room->name);
        $this->assertSame('3200.00', $room->price_per_night);
    }

    public function test_bookings_can_be_switched_off_and_on(): void
    {
        $room = Room::factory()->for($this->resort)->create();

        $this->actingAs($this->owner)->patch(route('owner.rooms.status', $room))->assertRedirect();
        $this->assertSame(RoomStatus::Inactive, $room->fresh()->status);
        $this->assertSame(0, Room::bookable()->count());

        $this->actingAs($this->owner)->patch(route('owner.rooms.status', $room));
        $this->assertSame(RoomStatus::Active, $room->fresh()->status);
        $this->assertSame(1, Room::bookable()->count());
    }

    public function test_inventory_cannot_drop_below_what_guests_already_hold(): void
    {
        $room = Room::factory()->for($this->resort)->create(['total_rooms' => 4]);

        // Two overlapping confirmed stays: two units held on the busiest night.
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13')->confirmed()->create();
        Booking::factory()->for($room)->forStay('2030-10-12', '2030-10-15')->confirmed()->create();
        // A cancelled stay must not count.
        Booking::factory()->for($room)->forStay('2030-10-11', '2030-10-14', 3)->cancelled()->create();

        $this->travelTo('2030-10-01 08:00:00');

        $this->actingAs($this->owner)->put(route('owner.rooms.update', $room), $this->payload(['name' => $room->name, 'total_rooms' => 1]))
            ->assertSessionHasErrors('total_rooms');
        $this->assertSame(4, $room->fresh()->total_rooms);

        // Two is exactly what is held, so it is allowed.
        $this->actingAs($this->owner)->put(route('owner.rooms.update', $room), $this->payload(['name' => $room->name, 'total_rooms' => 2]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $room->fresh()->total_rooms);
    }

    public function test_past_stays_do_not_hold_inventory(): void
    {
        $room = Room::factory()->for($this->resort)->create(['total_rooms' => 3]);
        Booking::factory()->for($room)->forStay('2030-09-01', '2030-09-04')->completed()->create();

        $this->travelTo('2030-10-01 08:00:00');

        $this->actingAs($this->owner)->put(route('owner.rooms.update', $room), $this->payload(['name' => $room->name, 'total_rooms' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $room->fresh()->total_rooms);
    }

    public function test_a_room_type_with_reservations_cannot_be_deleted(): void
    {
        $room = Room::factory()->for($this->resort)->create();
        Booking::factory()->for($room)->create();

        $this->actingAs($this->owner)->delete(route('owner.rooms.destroy', $room))->assertForbidden();
        $this->assertSame(1, Room::count());
    }

    public function test_a_room_type_without_reservations_can_be_deleted(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'Spare Room']);

        $this->actingAs($this->owner)->delete(route('owner.rooms.destroy', $room))->assertRedirect(route('owner.rooms.index'));

        $this->assertSame(0, Room::count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'room.deleted']);
    }

    public function test_an_owner_cannot_touch_another_resorts_rooms(): void
    {
        $otherResort = Resort::factory()->active()->create();
        $theirRoom = Room::factory()->for($otherResort)->create(['name' => 'Their Room', 'total_rooms' => 5]);

        $this->actingAs($this->owner)->get(route('owner.rooms.edit', $theirRoom))->assertForbidden();
        $this->actingAs($this->owner)->put(route('owner.rooms.update', $theirRoom), $this->payload())->assertForbidden();
        $this->actingAs($this->owner)->patch(route('owner.rooms.status', $theirRoom))->assertForbidden();
        $this->actingAs($this->owner)->delete(route('owner.rooms.destroy', $theirRoom))->assertForbidden();

        $theirRoom->refresh();
        $this->assertSame('Their Room', $theirRoom->name);
        $this->assertSame(5, $theirRoom->total_rooms);
        $this->assertSame(RoomStatus::Active, $theirRoom->status);
    }

    public function test_only_owners_may_manage_rooms(): void
    {
        $room = Room::factory()->for($this->resort)->create();

        foreach ([User::factory()->create(), User::factory()->superAdmin()->create()] as $other) {
            $this->actingAs($other)->get(route('owner.rooms.index'))->assertForbidden();
            $this->actingAs($other)->delete(route('owner.rooms.destroy', $room))->assertForbidden();
        }

        $this->assertSame(1, Room::count());
    }

    public function test_the_room_list_works_with_several_rooms(): void
    {
        // Guards against lazy loading in the list (strict mode throws on it).
        Room::factory()->count(3)->for($this->resort)->create();

        $this->actingAs($this->owner)->get(route('owner.rooms.index'))
            ->assertOk()
            ->assertSee('Room types');
    }

    public function test_the_room_list_shows_what_guests_hold(): void
    {
        $room = Room::factory()->for($this->resort)->create(['name' => 'Seaview Deluxe', 'total_rooms' => 4]);
        Booking::factory()->for($room)->forStay('2030-10-10', '2030-10-13', 2)->confirmed()->create();
        $this->travelTo('2030-10-01 08:00:00');

        $this->actingAs($this->owner)->get(route('owner.rooms.index'))
            ->assertOk()
            ->assertSee('Seaview Deluxe')
            ->assertSee('2 of 4 held on the busiest upcoming night');
    }
}
