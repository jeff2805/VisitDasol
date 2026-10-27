<?php

namespace Tests\Feature\Owner;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoomGalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($resort)->create(['name' => 'Seaview Deluxe']);
    }

    public function test_photos_can_be_added_and_removed(): void
    {
        $this->actingAs($this->owner)->post(route('owner.rooms.images.store', $this->room), [
            'images' => [UploadedFile::fake()->image('room.jpg', 1000, 700)],
        ])->assertRedirect();

        $image = $this->room->images()->sole();
        $this->assertStringStartsWith("rooms/{$this->room->id}/", $image->path);
        Storage::disk('public')->assertExists($image->path);

        $this->actingAs($this->owner)->delete(route('owner.rooms.images.destroy', [$this->room, $image]))->assertRedirect();
        $this->assertSame(0, $this->room->images()->count());
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_the_photo_limit_is_enforced(): void
    {
        $limit = config('visitdasol.uploads.gallery_limits.room');

        for ($i = 0; $i < $limit; $i++) {
            $this->room->images()->create(['path' => "rooms/{$this->room->id}/existing-{$i}.jpg"]);
        }

        $this->actingAs($this->owner)->post(route('owner.rooms.images.store', $this->room), [
            'images' => [UploadedFile::fake()->image('extra.jpg', 1000, 700)],
        ])->assertSessionHasErrors('images');

        $this->assertSame($limit, $this->room->images()->count());
    }

    public function test_unsafe_files_are_refused(): void
    {
        $this->actingAs($this->owner)->post(route('owner.rooms.images.store', $this->room), [
            'images' => [UploadedFile::fake()->create('shell.php', 20, 'application/x-php')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->room->images()->count());
    }

    public function test_an_owner_cannot_add_photos_to_another_resorts_room(): void
    {
        $theirRoom = Room::factory()->create();

        $this->actingAs($this->owner)->post(route('owner.rooms.images.store', $theirRoom), [
            'images' => [UploadedFile::fake()->image('room.jpg', 1000, 700)],
        ])->assertForbidden();

        $this->assertSame(0, $theirRoom->images()->count());
    }

    public function test_a_photo_from_another_room_cannot_be_deleted_through_this_room(): void
    {
        $theirRoom = Room::factory()->create();
        $theirImage = $theirRoom->images()->create(['path' => "rooms/{$theirRoom->id}/theirs.jpg"]);

        // Scoped route binding: the photo must belong to the room in the URL.
        $this->actingAs($this->owner)
            ->delete(route('owner.rooms.images.destroy', [$this->room, $theirImage]))
            ->assertNotFound();

        $this->assertDatabaseHas('room_images', ['id' => $theirImage->id]);
    }

    public function test_room_photos_appear_on_the_public_page(): void
    {
        $this->actingAs($this->owner)->post(route('owner.rooms.images.store', $this->room), [
            'images' => [UploadedFile::fake()->image('room.jpg', 1000, 700)],
        ]);
        $path = $this->room->images()->sole()->path;

        $this->post(route('logout'));
        $this->get(route('resorts.show', $this->room->resort))->assertOk()->assertSee($path);
    }
}
