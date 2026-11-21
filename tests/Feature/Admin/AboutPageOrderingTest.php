<?php

namespace Tests\Feature\Admin;

use App\Enums\AboutSection as Section;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use App\Services\AboutPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Putting the About page's lists in the office's order.
 *
 * Two lists have an order the office cares about: the destinations on the
 * page, and the photographs in the heritage gallery. Both are stored IN
 * order — a plain list the page walks — rather than stored with an order
 * beside them, so there is no second field to fall out of step.
 *
 * The control is a number box, not drag and drop. It needs no JavaScript,
 * it works from the keyboard, and it still works on a page whose scripts
 * failed to load — which is the one time somebody is most likely to be
 * fixing the site in a hurry.
 */
class AboutPageOrderingTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private AboutPageService $about;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->office = User::factory()->superAdmin()->create();
        $this->about = app(AboutPageService::class);
    }

    /** @return array<string, Destination> */
    private function threePlaces(): array
    {
        $category = DestinationCategory::factory()->create();

        return [
            'beach' => Destination::factory()->create(['name' => 'Tambobong Beach', 'category_id' => $category->id]),
            'deck' => Destination::factory()->create(['name' => 'Malacapas View Deck', 'category_id' => $category->id]),
            'cove' => Destination::factory()->create(['name' => 'Balinmanok Beach', 'category_id' => $category->id]),
        ];
    }

    /* Destinations ------------------------------------------------------- */

    public function test_the_office_numbers_the_destinations_into_order(): void
    {
        ['beach' => $beach, 'deck' => $deck, 'cove' => $cove] = $this->threePlaces();

        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore the Beauty of Dasol',
            'destination_ids' => [$beach->id, $deck->id, $cove->id],
            'destination_order' => [
                $beach->id => 2,
                $deck->id => 0,
                $cove->id => 1,
            ],
        ]);

        $stored = $this->about->preview()['destinations']['destination_ids'];

        $this->assertSame([$deck->id, $cove->id, $beach->id], $stored);
    }

    public function test_the_order_the_office_set_is_the_order_on_the_page(): void
    {
        ['beach' => $beach, 'deck' => $deck] = $this->threePlaces();

        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore the Beauty of Dasol',
            'destination_ids' => [$beach->id, $deck->id],
            'destination_order' => [$beach->id => 1, $deck->id => 0],
        ]);
        $this->actingAs($this->office)->patch(route('admin.about.publish', 'destinations'));

        $html = $this->get(route('about'))->assertOk()->getContent();

        /*
         * Compared by their card links, not by their names. The default
         * history text happens to mention Tambobong Beach, so looking for
         * the name finds the prose above the cards and reports an order
         * that has nothing to do with them.
         */
        $this->assertLessThan(
            strpos($html, route('destinations.show', $beach)),
            strpos($html, route('destinations.show', $deck)),
            'The cards are not in the order the office set.'
        );
    }

    /**
     * An unnumbered place goes last, not first. A blank box read as zero
     * would quietly jump it to the front.
     */
    public function test_an_unnumbered_destination_goes_to_the_end(): void
    {
        ['beach' => $beach, 'deck' => $deck] = $this->threePlaces();

        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore the Beauty of Dasol',
            'destination_ids' => [$beach->id, $deck->id],
            'destination_order' => [$deck->id => 5],
        ]);

        $stored = $this->about->preview()['destinations']['destination_ids'];

        $this->assertSame([$deck->id, $beach->id], $stored);
    }

    /**
     * The numbers are keyed by destination id, so unticking a box and
     * ticking it again does not move the place.
     */
    public function test_a_position_survives_a_box_being_unticked_and_ticked(): void
    {
        ['beach' => $beach, 'deck' => $deck, 'cove' => $cove] = $this->threePlaces();

        $order = [$beach->id => 2, $deck->id => 0, $cove->id => 1];

        // The cove is dropped...
        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore',
            'destination_ids' => [$beach->id, $deck->id],
            'destination_order' => $order,
        ]);

        $this->assertSame([$deck->id, $beach->id], $this->about->preview()['destinations']['destination_ids']);

        // ...and brought back, with the same numbers on the form.
        $this->actingAs($this->office)->put(route('admin.about.update', 'destinations'), [
            'heading' => 'Explore',
            'destination_ids' => [$beach->id, $deck->id, $cove->id],
            'destination_order' => $order,
        ]);

        $this->assertSame(
            [$deck->id, $cove->id, $beach->id],
            $this->about->preview()['destinations']['destination_ids'],
            'The cove came back somewhere other than where it was.'
        );
    }

    /* The heritage gallery ----------------------------------------------- */

    public function test_the_office_numbers_the_photographs_into_order(): void
    {
        $first = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('a.jpg', 800, 600));
        $second = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('b.jpg', 800, 600));
        $third = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('c.jpg', 800, 600));

        $this->actingAs($this->office)->put(route('admin.about.update', 'heritage'), [
            'heading' => 'Salt-making',
            'body' => 'Made by hand.',
            'gallery_path' => [$first, $second, $third],
            'gallery_caption' => ['First', 'Second', 'Third'],
            'gallery_order' => [2, 0, 1],
        ]);

        $captions = array_column($this->about->preview()['heritage']['images'], 'caption');

        $this->assertSame(['Second', 'Third', 'First'], $captions);
    }

    public function test_a_new_photograph_goes_on_the_end(): void
    {
        $existing = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('old.jpg', 800, 600));

        $this->actingAs($this->office)->put(route('admin.about.update', 'heritage'), [
            'heading' => 'Salt-making',
            'body' => 'Made by hand.',
            'gallery_path' => [$existing],
            'gallery_caption' => ['Already here'],
            'gallery_order' => [0],
            'new_images' => [UploadedFile::fake()->image('new.jpg', 800, 600)],
        ]);

        $images = $this->about->preview()['heritage']['images'];

        $this->assertCount(2, $images);
        $this->assertSame('Already here', $images[0]['caption']);
        $this->assertNull($images[1]['caption'], 'The new picture should be last and uncaptioned.');
    }

    public function test_a_photograph_left_out_is_removed_from_the_gallery(): void
    {
        $keep = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('keep.jpg', 800, 600));
        $drop = $this->about->storeImage(Section::Heritage, UploadedFile::fake()->image('drop.jpg', 800, 600));

        $this->actingAs($this->office)->put(route('admin.about.update', 'heritage'), [
            'heading' => 'Salt-making',
            'body' => 'Made by hand.',
            'gallery_path' => [$keep],
            'gallery_caption' => ['Kept'],
            'gallery_order' => [0],
        ]);

        $paths = array_column($this->about->preview()['heritage']['images'], 'path');

        $this->assertSame([$keep], $paths);

        // Still on disk: the live page may still be showing it until this
        // change is published.
        Storage::disk('public')->assertExists($drop);
    }

    public function test_a_silly_position_is_refused(): void
    {
        ['beach' => $beach] = $this->threePlaces();

        $this->actingAs($this->office)
            ->put(route('admin.about.update', 'destinations'), [
                'heading' => 'Explore',
                'destination_ids' => [$beach->id],
                'destination_order' => [$beach->id => -5],
            ])
            ->assertSessionHasErrors('destination_order.'.$beach->id);
    }
}
