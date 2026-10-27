<?php

namespace Tests\Feature\Owner;

use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResortGalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->create();
    }

    public function test_photos_can_be_uploaded(): void
    {
        $this->actingAs($this->owner)->post(route('owner.resort.images.store'), [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 1000, 700),
                UploadedFile::fake()->image('two.jpg', 1000, 700),
            ],
        ])->assertRedirect();

        $this->assertSame(2, $this->resort->images()->count());

        foreach ($this->resort->images as $image) {
            $this->assertStringStartsWith("resorts/{$this->resort->id}/gallery/", $image->path);
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_the_gallery_limit_cannot_be_passed_even_in_several_uploads(): void
    {
        $limit = config('visitdasol.uploads.gallery_limits.resort');

        for ($i = 0; $i < $limit - 1; $i++) {
            $this->resort->images()->create(['path' => "resorts/{$this->resort->id}/gallery/existing-{$i}.jpg"]);
        }

        $this->actingAs($this->owner)->post(route('owner.resort.images.store'), [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 1000, 700),
                UploadedFile::fake()->image('two.jpg', 1000, 700),
            ],
        ])->assertSessionHasErrors('images');

        $this->assertSame($limit - 1, $this->resort->images()->count());
    }

    public function test_unsafe_files_are_refused(): void
    {
        $this->actingAs($this->owner)->post(route('owner.resort.images.store'), [
            'images' => [UploadedFile::fake()->create('shell.php', 20, 'application/x-php')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->resort->images()->count());
    }

    public function test_a_photo_can_be_made_the_cover_and_removed(): void
    {
        $this->actingAs($this->owner)->post(route('owner.resort.images.store'), [
            'images' => [UploadedFile::fake()->image('one.jpg', 1000, 700)],
        ]);

        $image = $this->resort->images()->sole();

        $this->actingAs($this->owner)->patch(route('owner.resort.images.cover', $image))->assertRedirect();
        $this->assertSame($image->path, $this->resort->fresh()->main_image);

        $this->actingAs($this->owner)->delete(route('owner.resort.images.destroy', $image))->assertRedirect();

        $this->assertSame(0, $this->resort->images()->count());
        $this->assertNull($this->resort->fresh()->main_image, 'Removing the cover photo must clear it from the resort.');
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_an_owner_cannot_touch_another_owners_photos(): void
    {
        $otherResort = Resort::factory()->create();
        $otherImage = $otherResort->images()->create(['path' => 'resorts/'.$otherResort->id.'/gallery/theirs.jpg']);

        $this->actingAs($this->owner)->delete(route('owner.resort.images.destroy', $otherImage))->assertForbidden();
        $this->actingAs($this->owner)->patch(route('owner.resort.images.cover', $otherImage))->assertForbidden();

        $this->assertDatabaseHas('resort_images', ['id' => $otherImage->id]);
        $this->assertNull($otherResort->fresh()->main_image);
    }

    public function test_photos_are_shown_on_the_public_page_once_approved(): void
    {
        $this->actingAs($this->owner)->post(route('owner.resort.images.store'), [
            'images' => [UploadedFile::fake()->image('one.jpg', 1000, 700)],
        ]);
        $this->resort->forceFill(['status' => \App\Enums\ResortStatus::Active])->save();
        $path = $this->resort->images()->sole()->path;

        $this->post(route('logout'));
        $this->get(route('resorts.show', $this->resort))->assertOk()->assertSee($path);
    }
}
