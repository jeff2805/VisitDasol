<?php

namespace Tests\Feature\Admin;

use App\Models\Amenity;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryAndAmenityTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create();
    }

    /* Destination categories --------------------------------------------- */

    public function test_a_category_can_be_added_renamed_and_deleted(): void
    {
        $this->actingAs($this->mto)->post(route('admin.destination-categories.store'), [
            'name' => 'Waterfall', 'icon' => 'water',
        ])->assertRedirect();

        $category = DestinationCategory::sole();
        $this->assertSame('waterfall', $category->slug);

        $this->actingAs($this->mto)->put(route('admin.destination-categories.update', $category), [
            'name' => 'Waterfalls', 'icon' => 'water',
        ]);
        $this->assertSame('Waterfalls', $category->fresh()->name);

        $this->actingAs($this->mto)->delete(route('admin.destination-categories.destroy', $category));
        $this->assertSame(0, DestinationCategory::count());
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $category = DestinationCategory::factory()->create(['name' => 'Beach']);
        Destination::factory()->for($category, 'category')->create();

        $this->actingAs($this->mto)
            ->delete(route('admin.destination-categories.destroy', $category))
            ->assertSessionHas('error');

        $this->assertSame(1, DestinationCategory::count());
    }

    public function test_category_names_must_be_unique(): void
    {
        DestinationCategory::factory()->create(['name' => 'Beach']);

        $this->actingAs($this->mto)->post(route('admin.destination-categories.store'), ['name' => 'Beach'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, DestinationCategory::count());
    }

    public function test_an_icon_name_must_look_like_an_icon_name(): void
    {
        $this->actingAs($this->mto)->post(route('admin.destination-categories.store'), [
            'name' => 'Cave', 'icon' => '<script>alert(1)</script>',
        ])->assertSessionHasErrors('icon');
    }

    /* Amenities ----------------------------------------------------------- */

    public function test_an_amenity_can_be_added_and_switched_off(): void
    {
        $this->actingAs($this->mto)->post(route('admin.amenities.store'), [
            'name' => 'Beach volleyball', 'icon' => 'trophy',
        ])->assertRedirect();

        $amenity = Amenity::sole();
        $this->assertTrue($amenity->is_active);

        $this->actingAs($this->mto)->patch(route('admin.amenities.toggle', $amenity));
        $this->assertFalse($amenity->fresh()->is_active);

        // Switched-off amenities disappear from the owner's form and the public filter.
        // The session is flushed first so the flash message about this amenity
        // (which names it) cannot be mistaken for the checkbox itself.
        $owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($owner, 'owner')->create();
        $checkbox = 'id="amenity-'.$amenity->id.'"';

        $this->flushSession();
        $this->actingAs($owner)->get(route('owner.resort.edit'))->assertOk()->assertDontSee($checkbox, false);
        $this->get(route('resorts.index'))->assertOk()->assertDontSee($checkbox, false);

        $this->actingAs($this->mto)->patch(route('admin.amenities.toggle', $amenity));
        $this->assertTrue($amenity->fresh()->is_active);

        $this->flushSession();
        $this->actingAs($owner)->get(route('owner.resort.edit'))->assertSee($checkbox, false);
        $this->get(route('resorts.index'))->assertSee('Beach volleyball');
    }

    public function test_an_amenity_in_use_cannot_be_deleted(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Wi-Fi']);
        Resort::factory()->create()->amenities()->attach($amenity);

        $this->actingAs($this->mto)->delete(route('admin.amenities.destroy', $amenity))->assertSessionHas('error');
        $this->assertSame(1, Amenity::count());
    }

    public function test_an_unused_amenity_can_be_deleted(): void
    {
        $amenity = Amenity::factory()->create();

        $this->actingAs($this->mto)->delete(route('admin.amenities.destroy', $amenity))->assertSessionHas('success');
        $this->assertSame(0, Amenity::count());
    }

    public function test_switching_an_amenity_off_keeps_it_on_resorts_that_use_it(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Videoke']);
        $resort = Resort::factory()->active()->create();
        $resort->amenities()->attach($amenity);

        $this->actingAs($this->mto)->patch(route('admin.amenities.toggle', $amenity));

        $this->assertSame(1, $resort->fresh()->amenities()->count());
        $this->post(route('logout'));
        $this->get(route('resorts.show', $resort))->assertOk()->assertSee('Videoke');
    }

    public function test_owners_cannot_manage_these_lists(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $amenity = Amenity::factory()->create();

        $this->actingAs($owner)->get(route('admin.amenities.index'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.amenities.store'), ['name' => 'Free everything'])->assertForbidden();
        $this->actingAs($owner)->delete(route('admin.amenities.destroy', $amenity))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.destination-categories.index'))->assertForbidden();

        $this->assertSame(1, Amenity::count());
    }
}
