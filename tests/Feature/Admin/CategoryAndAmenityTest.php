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

    /*
     * THE OFFICE NO LONGER MANAGES THIS LIST.
     *
     * admin/amenities — add, rename, switch off, delete — has been
     * removed, so the tests that posted to those routes have gone with
     * them. What stayed is everything amenities still do: owners tick
     * them, visitors filter by them, and is_active still decides who
     * sees which. Those are driven off the model here rather than off
     * a screen, because the behaviour is what matters and the screen
     * is what went.
     */

    public function test_an_amenity_switched_off_leaves_the_owner_form_and_the_public_filter(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Beach volleyball']);

        $this->assertTrue($amenity->is_active);

        $owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($owner, 'owner')->create();
        $checkbox = 'id="amenity-'.$amenity->id.'"';

        // On, so both places offer it.
        $this->actingAs($owner)->get(route('owner.resort.edit'))->assertOk()->assertSee($checkbox, false);
        $this->get(route('resorts.index'))->assertOk()->assertSee('Beach volleyball');

        $amenity->forceFill(['is_active' => false])->save();

        $this->flushSession();

        // Off, so neither does.
        $this->actingAs($owner)->get(route('owner.resort.edit'))->assertOk()->assertDontSee($checkbox, false);
        $this->get(route('resorts.index'))->assertOk()->assertDontSee($checkbox, false);
    }

    /**
     * Switching one off hides it from the pickers; it does not strip it
     * from resorts that already said they have it. A resort does not
     * lose its videoke because the office stopped offering the tickbox.
     */
    public function test_switching_an_amenity_off_keeps_it_on_resorts_that_use_it(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Videoke']);
        $resort = Resort::factory()->active()->create();
        $resort->amenities()->attach($amenity);

        $amenity->forceFill(['is_active' => false])->save();

        $this->assertSame(1, $resort->fresh()->amenities()->count());
        $this->get(route('resorts.show', $resort))->assertOk()->assertSee('Videoke');
    }

    /** The category list is still the office's alone. */
    public function test_owners_cannot_manage_these_lists(): void
    {
        $owner = User::factory()->resortOwner()->create();

        $this->actingAs($owner)->get(route('admin.destination-categories.index'))->assertForbidden();
    }

    /**
     * The office's amenity screens are gone, not merely hidden.
     *
     * A removed menu item is not a removed feature: the routes have to
     * be gone too, or the page is still there for anyone who types the
     * address.
     */
    public function test_the_office_has_no_amenity_screens_any_more(): void
    {
        foreach ([
            'admin.amenities.index',
            'admin.amenities.store',
            'admin.amenities.update',
            'admin.amenities.toggle',
            'admin.amenities.destroy',
        ] as $name) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Route::has($name),
                "The route {$name} should have been removed with the screens."
            );
        }

        $this->actingAs($this->mto)->get('/admin/amenities')->assertNotFound();
    }
}
