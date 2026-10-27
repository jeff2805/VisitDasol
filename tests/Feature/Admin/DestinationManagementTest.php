<?php

namespace Tests\Feature\Admin;

use App\Enums\DestinationStatus;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DestinationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private DestinationCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create();
        $this->category = DestinationCategory::factory()->create(['name' => 'Beach']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'name' => 'White Sand Cove',
            'description' => 'A quiet stretch of shoreline reached by a short walk from the main road.',
            'address' => 'Sitio Malimpin',
            'barangay' => 'Tambobong',
            'latitude' => 16.01,
            'longitude' => 119.864,
        ], $overrides);
    }

    public function test_a_destination_can_be_added_and_is_published(): void
    {
        $this->actingAs($this->mto)->get(route('admin.destinations.create'))->assertOk();

        $this->actingAs($this->mto)->post(route('admin.destinations.store'), $this->payload([
            // Must be ignored.
            'status' => 'inactive',
            'is_featured' => true,
        ]))->assertRedirect();

        $destination = Destination::sole();
        $this->assertSame(DestinationStatus::Active, $destination->status);
        $this->assertFalse($destination->is_featured);
        $this->assertSame($this->mto->id, $destination->created_by);
        $this->assertSame('white-sand-cove', $destination->slug);
        $this->assertDatabaseHas('activity_logs', ['action' => 'destination.created', 'subject_id' => $destination->id]);

        // Visitors can see it at once.
        $this->post(route('logout'));
        $this->get(route('destinations.index'))->assertOk()->assertSee('White Sand Cove');
        $this->get(route('destinations.show', $destination))->assertOk();
    }

    public function test_destination_validation(): void
    {
        $cases = [
            ['name' => ''],
            ['description' => 'Too short.'],
            ['category_id' => 999],
            ['barangay' => 'Not A Barangay'],
            ['latitude' => 14.5],
            ['longitude' => 121.5],
        ];

        foreach ($cases as $override) {
            $this->actingAs($this->mto)
                ->post(route('admin.destinations.store'), $this->payload($override))
                ->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertSame(0, Destination::count());
    }

    public function test_hiding_a_destination_takes_it_off_the_public_site(): void
    {
        $destination = Destination::factory()->featured()->create(['name' => 'White Sand Cove']);

        $this->actingAs($this->mto)->patch(route('admin.destinations.status', $destination))->assertRedirect();

        $destination->refresh();
        $this->assertSame(DestinationStatus::Inactive, $destination->status);
        $this->assertFalse($destination->is_featured, 'A hidden destination cannot stay on the home page.');

        $this->post(route('logout'));
        $this->get(route('destinations.index'))->assertOk()->assertDontSee('White Sand Cove');
        $this->get(route('destinations.show', $destination))->assertNotFound();
        $this->get(route('home'))->assertOk()->assertDontSee('White Sand Cove');
    }

    public function test_publishing_brings_it_back(): void
    {
        $destination = Destination::factory()->inactive()->create(['name' => 'White Sand Cove']);

        $this->actingAs($this->mto)->patch(route('admin.destinations.status', $destination));

        $this->assertSame(DestinationStatus::Active, $destination->fresh()->status);
        $this->get(route('destinations.index'))->assertSee('White Sand Cove');
    }

    public function test_only_published_destinations_can_be_featured(): void
    {
        $published = Destination::factory()->create();
        $hidden = Destination::factory()->inactive()->create();

        $this->actingAs($this->mto)->patch(route('admin.destinations.feature', $published))->assertRedirect();
        $this->assertTrue($published->fresh()->is_featured);

        $this->actingAs($this->mto)->patch(route('admin.destinations.feature', $published));
        $this->assertFalse($published->fresh()->is_featured, 'The action toggles.');

        $this->actingAs($this->mto)->patch(route('admin.destinations.feature', $hidden))->assertForbidden();
        $this->assertFalse($hidden->fresh()->is_featured);
    }

    public function test_a_destination_can_be_edited(): void
    {
        $destination = Destination::factory()->create(['name' => 'White Sand Cove', 'barangay' => 'Uli']);

        $this->actingAs($this->mto)->put(route('admin.destinations.update', $destination), $this->payload([
            'name' => 'White Sand Cove and Rock Pools',
            'barangay' => 'Tambobong',
        ]))->assertRedirect(route('admin.destinations.index'));

        $destination->refresh();
        $this->assertSame('White Sand Cove and Rock Pools', $destination->name);
        $this->assertSame('Tambobong', $destination->barangay);
        $this->assertSame('white-sand-cove', $destination->slug, 'The public address stays the same.');
    }

    public function test_deleting_removes_the_destination_and_its_files(): void
    {
        Storage::fake('public');

        $this->actingAs($this->mto)->post(route('admin.destinations.store'), $this->payload([
            'main_image' => UploadedFile::fake()->image('cove.jpg', 1000, 700),
        ]));
        $destination = Destination::sole();

        $this->actingAs($this->mto)->post(route('admin.destinations.images.store', $destination), [
            'images' => [UploadedFile::fake()->image('extra.jpg', 1000, 700)],
        ]);

        $cover = $destination->fresh()->main_image;
        $gallery = $destination->images()->sole()->path;

        $this->actingAs($this->mto)->delete(route('admin.destinations.destroy', $destination))->assertRedirect();

        $this->assertSame(0, Destination::count());
        $this->assertSame(0, \App\Models\DestinationImage::count());
        Storage::disk('public')->assertMissing($cover);
        Storage::disk('public')->assertMissing($gallery);
    }

    public function test_the_list_can_be_filtered(): void
    {
        $viewpoint = DestinationCategory::factory()->create(['name' => 'Viewpoint']);
        Destination::factory()->for($this->category, 'category')->create(['name' => 'White Sand Cove']);
        Destination::factory()->for($viewpoint, 'category')->inactive()->create(['name' => 'Hilltop Lookout']);

        $this->actingAs($this->mto)->get(route('admin.destinations.index', ['status' => 'inactive']))
            ->assertSee('Hilltop Lookout')->assertDontSee('White Sand Cove');

        $this->actingAs($this->mto)->get(route('admin.destinations.index', ['category' => $this->category->slug]))
            ->assertSee('White Sand Cove')->assertDontSee('Hilltop Lookout');

        $this->actingAs($this->mto)->get(route('admin.destinations.index', ['q' => 'hilltop']))
            ->assertSee('Hilltop Lookout')->assertDontSee('White Sand Cove');
    }

    public function test_photos_can_be_managed(): void
    {
        Storage::fake('public');
        $destination = Destination::factory()->create();

        $this->actingAs($this->mto)->post(route('admin.destinations.images.store', $destination), [
            'images' => [UploadedFile::fake()->image('one.jpg', 1000, 700)],
        ])->assertRedirect();

        $image = $destination->images()->sole();
        Storage::disk('public')->assertExists($image->path);

        $this->actingAs($this->mto)->patch(route('admin.destinations.images.cover', [$destination, $image]));
        $this->assertSame($image->path, $destination->fresh()->main_image);

        $this->actingAs($this->mto)->delete(route('admin.destinations.images.destroy', [$destination, $image]));
        $this->assertSame(0, $destination->images()->count());
        $this->assertNull($destination->fresh()->main_image);
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_a_photo_of_another_destination_cannot_be_reached(): void
    {
        $mine = Destination::factory()->create();
        $theirs = Destination::factory()->create();
        $theirImage = $theirs->images()->create(['path' => "destinations/{$theirs->id}/gallery/theirs.jpg"]);

        $this->actingAs($this->mto)
            ->delete(route('admin.destinations.images.destroy', [$mine, $theirImage]))
            ->assertNotFound();

        $this->assertDatabaseHas('destination_images', ['id' => $theirImage->id]);
    }

    public function test_owners_and_tourists_cannot_manage_destinations(): void
    {
        $destination = Destination::factory()->create(['name' => 'White Sand Cove']);

        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.destinations.index'))->assertForbidden();
            $this->actingAs($intruder)->post(route('admin.destinations.store'), $this->payload())->assertForbidden();
            $this->actingAs($intruder)->patch(route('admin.destinations.status', $destination))->assertForbidden();
            $this->actingAs($intruder)->delete(route('admin.destinations.destroy', $destination))->assertForbidden();
        }

        $this->assertSame(1, Destination::count());
        $this->assertSame(DestinationStatus::Active, $destination->fresh()->status);
    }
}
