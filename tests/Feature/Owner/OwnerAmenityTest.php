<?php

namespace Tests\Feature\Owner;

use App\Models\Amenity;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resort owners keeping the shared amenity list.
 *
 * THE RULE THIS FILE EXISTS TO HOLD: add, and nothing else.
 *
 * There is one amenities table and every resort picks from it, which is
 * what makes "filter by amenity" work across the municipality. So an
 * owner may put something on the list that is genuinely missing, and
 * may not rename, switch off or delete anything — one owner deleting
 * "Wi-Fi" would strip it from every resort that had ticked it and from
 * the public filter in the same stroke.
 *
 * The tourism office's own amenity screens were removed before this was
 * written, so this is the only way an amenity reaches the list now.
 */
class OwnerAmenityTest extends TestCase
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

    /* Adding --------------------------------------------------------------- */

    public function test_an_owner_adds_something_the_list_was_missing(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), [
                'name' => 'Videoke',
                'icon' => 'music-note-beamed',
            ])
            ->assertRedirect(route('owner.amenities.index'))
            ->assertSessionHasNoErrors();

        $amenity = Amenity::sole();

        $this->assertSame('Videoke', $amenity->name);
        $this->assertSame('music-note-beamed', $amenity->icon);

        // Live at once. Added but invisible would have gained nobody anything.
        $this->assertTrue($amenity->is_active);
    }

    /**
     * Ticked for the asker straight away.
     *
     * They are adding it because they offer it. Making them walk to
     * another page to tick what they have just typed is a step for
     * nothing.
     */
    public function test_what_an_owner_adds_is_ticked_on_their_own_resort(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), ['name' => 'Beach volleyball']);

        $amenity = Amenity::sole();

        $this->assertTrue(
            $this->resort->fresh()->amenities->contains($amenity),
            'The new amenity should already be on the resort that asked for it.'
        );
    }

    /** And it then appears where amenities appear. */
    public function test_a_new_amenity_reaches_the_form_and_the_public_filter(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), ['name' => 'Kayak hire']);

        $amenity = Amenity::sole();
        $checkbox = 'id="amenity-'.$amenity->id.'"';

        $this->flushSession();

        $this->actingAs($this->owner)
            ->get(route('owner.resort.edit'))
            ->assertOk()
            ->assertSee($checkbox, false);

        $this->flushSession();

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee($checkbox, false);
    }

    /**
     * Two spellings of one thing split the filter in two, and each half
     * then finds only some of the resorts that have it.
     */
    public function test_the_same_amenity_cannot_be_added_twice(): void
    {
        Amenity::factory()->create(['name' => 'Wi-Fi']);

        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), ['name' => 'Wi-Fi'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Amenity::count());
    }

    public function test_a_nonsense_icon_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), [
                'name' => 'Cave tour',
                'icon' => '<script>alert(1)</script>',
            ])
            ->assertSessionHasErrors('icon');

        $this->assertSame(0, Amenity::count());
    }

    public function test_adding_is_written_to_the_audit_trail(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.amenities.store'), ['name' => 'Firepit']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'amenity.added',
            'user_id' => $this->owner->id,
            'subject_type' => 'amenity',
        ]);
    }

    /* What an owner may NOT do --------------------------------------------- */

    /**
     * No rename, no switch off, no delete — not hidden, absent.
     *
     * A removed button is not a removed power. The routes have to be
     * gone, or anyone who types the address still has them.
     */
    public function test_there_is_no_way_for_an_owner_to_change_or_remove_an_entry(): void
    {
        foreach ([
            'owner.amenities.update',
            'owner.amenities.toggle',
            'owner.amenities.destroy',
            // And the office's, which were removed earlier.
            'admin.amenities.index',
            'admin.amenities.destroy',
        ] as $name) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Route::has($name),
                "The route {$name} must not exist: it would reach into other resorts' listings."
            );
        }
    }

    /** An owner typing the addresses by hand gets nowhere, and the row is untouched. */
    public function test_the_obvious_urls_are_not_there(): void
    {
        $amenity = Amenity::factory()->create(['name' => 'Wi-Fi']);

        $this->actingAs($this->owner)
            ->put('/owner/amenities/'.$amenity->id, ['name' => 'Renamed'])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->delete('/owner/amenities/'.$amenity->id)
            ->assertNotFound();

        $this->assertSame('Wi-Fi', $amenity->fresh()->name);
        $this->assertSame(1, Amenity::count());
    }

    /* Who may reach it ------------------------------------------------------ */

    public function test_a_tourist_cannot_add_to_the_list(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->get(route('owner.amenities.index'))->assertForbidden();
        $this->actingAs($tourist)
            ->post(route('owner.amenities.store'), ['name' => 'Free everything'])
            ->assertForbidden();

        $this->assertSame(0, Amenity::count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('owner.amenities.index'))->assertRedirect(route('login'));
    }

    /* The page -------------------------------------------------------------- */

    public function test_the_page_lists_what_is_available_and_marks_the_owners_own(): void
    {
        $mine = Amenity::factory()->create(['name' => 'Swimming pool']);
        $theirs = Amenity::factory()->create(['name' => 'Jet ski']);

        $this->resort->amenities()->attach($mine);

        $response = $this->actingAs($this->owner)
            ->get(route('owner.amenities.index'))
            ->assertOk();

        $response->assertSee('Swimming pool');
        $response->assertSee('Jet ski');

        // And says the list is not this owner's alone to change.
        $response->assertSee('This list is shared');
    }

    /** Something switched off is offered to nobody, here included. */
    public function test_a_switched_off_amenity_is_not_listed(): void
    {
        $off = Amenity::factory()->create(['name' => 'Retired thing']);
        $off->forceFill(['is_active' => false])->save();

        $this->actingAs($this->owner)
            ->get(route('owner.amenities.index'))
            ->assertOk()
            ->assertDontSee('Retired thing');
    }
}
