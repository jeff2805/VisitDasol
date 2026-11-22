<?php

namespace Tests\Feature\Public;

use App\Models\Resort;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room photos on a resort page can be opened.
 *
 * They were bare <img> tags sitting three to a row, identical in every
 * visible way to the resort's own header thumbnails, which do respond to
 * a click. A guest deciding where to sleep would click the picture of the
 * bed and get nothing.
 *
 * The second thing fixed here is quieter and worse: the strip took the
 * first three photos and the rest existed nowhere on the public site. An
 * owner who uploaded six photos of a room had three of them seen by no
 * one, with nothing on the page to suggest they were there.
 */
class RoomPhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string|null>  $captions  one entry per photo
     */
    private function roomWithPhotos(array $captions): Room
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $room = Room::factory()->for($resort)->create(['name' => 'Deluxe Seaview Room']);

        // Created through the relation: RoomImage has no factory, following
        // what RoomDeletionTest already does.
        foreach (array_values($captions) as $index => $caption) {
            $room->images()->create([
                'path' => "rooms/{$room->id}/photo-{$index}.jpg",
                'caption' => $caption,
                'sort_order' => $index,
            ]);
        }

        return $room;
    }

    private function page(Room $room): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('resorts.show', $room->resort));
    }

    /* Clickable -------------------------------------------------------- */

    public function test_each_room_photo_is_a_button_that_opens_the_gallery(): void
    {
        $room = $this->roomWithPhotos(['Bed by the window', 'Bathroom']);

        $html = $this->page($room)->assertOk()->getContent();

        $this->assertStringContainsString('class="vd-photo-button"', $html);
        $this->assertStringContainsString('data-bs-target="#room-photos-'.$room->id.'"', $html);

        // The dialog it points at has to exist, or the button is a no-op.
        $this->assertStringContainsString('id="room-photos-'.$room->id.'"', $html);
    }

    /**
     * Clicking the third thumbnail must open the third photo. Without the
     * index travelling, every thumbnail opens the first one and the guest
     * has to navigate back to the photo they were already looking at.
     */
    public function test_a_thumbnail_carries_the_photo_it_is_showing(): void
    {
        $room = $this->roomWithPhotos(['One', 'Two', 'Three']);

        $html = $this->page($room)->getContent();

        foreach ([0, 1, 2] as $index) {
            $this->assertStringContainsString('data-photo-index="'.$index.'"', $html);
        }
    }

    /**
     * A button with no text inside it needs a name of its own, or a screen
     * reader announces "button" and nothing else.
     */
    public function test_the_buttons_say_what_they_open(): void
    {
        $room = $this->roomWithPhotos(['One', 'Two']);

        $this->page($room)
            ->assertSee('View photo 1 of Deluxe Seaview Room', escape: false)
            ->assertSee('View photo 2 of Deluxe Seaview Room', escape: false);
    }

    /* Nothing hidden --------------------------------------------------- */

    /**
     * The strip still shows three. All six must be reachable.
     */
    public function test_every_photo_reaches_the_gallery_not_just_the_first_three(): void
    {
        $room = $this->roomWithPhotos([
            'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth',
        ]);

        $response = $this->page($room)->assertOk();

        foreach (['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth'] as $caption) {
            $response->assertSee($caption, escape: false);
        }

        // And the gallery says where you are in the set.
        $response->assertSee('Photo 6 of 6', escape: false);
    }

    /**
     * The page must admit there is more behind the third thumbnail. This is
     * the part a test is actually needed for: three photos of a six-photo
     * room looks exactly like a complete set.
     */
    public function test_the_strip_says_how_many_photos_it_is_not_showing(): void
    {
        $room = $this->roomWithPhotos(['One', 'Two', 'Three', 'Four', 'Five']);

        $this->page($room)
            ->assertSee('+2', escape: false)
            ->assertSee('View all 5 photos of Deluxe Seaview Room', escape: false);
    }

    public function test_a_room_whose_photos_all_fit_makes_no_such_claim(): void
    {
        $room = $this->roomWithPhotos(['One', 'Two', 'Three']);

        $html = $this->page($room)->getContent();

        $this->assertStringNotContainsString('vd-photo-button__more', $html);
        $this->assertStringNotContainsString('View all 3 photos', $html);
    }

    /* Edges ------------------------------------------------------------ */

    public function test_a_room_with_no_photos_says_so_and_opens_nothing(): void
    {
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['name' => 'Plain Room']);

        $html = $this->get(route('resorts.show', $resort))->assertOk()->getContent();

        $this->assertStringContainsString('No photo of this room yet', $html);

        // No empty dialog left behind for a room that has nothing to show.
        $this->assertStringNotContainsString('id="room-photos-'.$room->id.'"', $html);
    }

    /**
     * One photo needs no way to step between photos, and offering arrows
     * that cannot go anywhere is worse than offering none.
     */
    public function test_a_single_photo_gets_no_next_and_previous(): void
    {
        $room = $this->roomWithPhotos(['Only one']);

        $html = $this->page($room)->getContent();

        $this->assertStringContainsString('id="room-photos-'.$room->id.'"', $html);
        $this->assertStringNotContainsString('Next photo', $html);
        $this->assertStringNotContainsString('Previous photo', $html);
    }

    /**
     * An owner who uploaded a photo without a caption still gets a usable
     * gallery; the position stands in for the missing words.
     */
    public function test_a_photo_without_a_caption_still_gets_a_label(): void
    {
        $room = $this->roomWithPhotos([null, null]);

        $this->page($room)
            ->assertOk()
            ->assertSee('Photo 1 of 2', escape: false)
            ->assertSee('Photo 2 of 2', escape: false);
    }

    /**
     * Two rooms on one page must open their own photos, not each other's.
     */
    public function test_two_rooms_keep_their_galleries_apart(): void
    {
        $resort = Resort::factory()->active()->create();

        $first = Room::factory()->for($resort)->create(['name' => 'Seaview Room']);
        $first->images()->create(['path' => 'rooms/a.jpg', 'caption' => 'Seaview bed', 'sort_order' => 0]);

        $second = Room::factory()->for($resort)->create(['name' => 'Garden Room']);
        $second->images()->create(['path' => 'rooms/b.jpg', 'caption' => 'Garden bed', 'sort_order' => 0]);

        $html = $this->get(route('resorts.show', $resort))->assertOk()->getContent();

        $this->assertStringContainsString('id="room-photos-'.$first->id.'"', $html);
        $this->assertStringContainsString('id="room-photos-'.$second->id.'"', $html);
        $this->assertNotSame($first->id, $second->id);
    }
}
