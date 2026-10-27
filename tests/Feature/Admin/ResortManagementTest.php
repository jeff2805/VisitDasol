<?php

namespace Tests\Feature\Admin;

use App\Enums\ResortStatus;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResortManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create();
    }

    public function test_resorts_can_be_searched_and_filtered(): void
    {
        Resort::factory()->active()->create(['name' => 'Salt Breeze Resort', 'barangay' => 'Tambobong']);
        Resort::factory()->create(['name' => 'Coral Point Resort', 'barangay' => 'Uli']);
        $owned = Resort::factory()->for(User::factory()->resortOwner()->create(['last_name' => 'Villanueva']), 'owner')
            ->create(['name' => 'Bayside Retreat']);

        $this->actingAs($this->mto)->get(route('admin.resorts.index'))
            ->assertOk()->assertSee('Salt Breeze Resort')->assertSee('Coral Point Resort');

        $this->actingAs($this->mto)->get(route('admin.resorts.index', ['status' => 'pending']))
            ->assertSee('Coral Point Resort')->assertDontSee('Salt Breeze Resort');

        $this->actingAs($this->mto)->get(route('admin.resorts.index', ['barangay' => 'Tambobong']))
            ->assertSee('Salt Breeze Resort')->assertDontSee('Coral Point Resort');

        $this->actingAs($this->mto)->get(route('admin.resorts.index', ['q' => 'Villanueva']))
            ->assertSee($owned->name)->assertDontSee('Salt Breeze Resort');
    }

    public function test_taking_a_listing_offline_needs_a_reason_and_hides_it(): void
    {
        $resort = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);

        $this->actingAs($this->mto)
            ->patch(route('admin.resorts.deactivate', $resort), ['review_remarks' => ''])
            ->assertSessionHasErrors('review_remarks');
        $this->assertSame(ResortStatus::Active, $resort->fresh()->status);

        $this->actingAs($this->mto)
            ->patch(route('admin.resorts.deactivate', $resort), ['review_remarks' => 'Closed for repairs until further notice.'])
            ->assertRedirect();

        $resort->refresh();
        $this->assertSame(ResortStatus::Inactive, $resort->status);
        $this->assertSame(0, Resort::publiclyVisible()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.deactivated', 'subject_id' => $resort->id]);

        $this->post(route('logout'));
        $this->get(route('resorts.show', $resort))->assertNotFound();

        // The owner sees why.
        $this->actingAs($resort->owner)->get(route('owner.resort.show'))
            ->assertOk()->assertSee('Hidden from the public');
    }

    public function test_a_listing_can_be_put_back_online(): void
    {
        $resort = Resort::factory()->inactive()->create();

        $this->actingAs($this->mto)->patch(route('admin.resorts.activate', $resort))->assertRedirect();

        $this->assertSame(ResortStatus::Active, $resort->fresh()->status);
        $this->assertSame(1, Resort::publiclyVisible()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.activated', 'subject_id' => $resort->id]);
    }

    public function test_only_live_listings_can_be_featured(): void
    {
        $live = Resort::factory()->active()->create(['name' => 'Salt Breeze Resort']);
        $pending = Resort::factory()->create();

        $this->actingAs($this->mto)->patch(route('admin.resorts.feature', $live))->assertRedirect();
        $this->assertTrue($live->fresh()->is_featured);

        $this->actingAs($this->mto)->patch(route('admin.resorts.feature', $live));
        $this->assertFalse($live->fresh()->is_featured, 'The action toggles.');

        $this->actingAs($this->mto)->patch(route('admin.resorts.feature', $pending))->assertForbidden();
        $this->assertFalse($pending->fresh()->is_featured);
    }

    public function test_the_office_can_correct_details_without_changing_the_status(): void
    {
        $resort = Resort::factory()->active()->create(['barangay' => 'Uli']);

        $this->actingAs($this->mto)->put(route('admin.resorts.update', $resort), [
            'name' => $resort->name,
            'description' => $resort->description,
            'address' => $resort->address,
            'barangay' => 'Tambobong',
            'latitude' => 15.995,
            'longitude' => 119.875,
            'contact_number' => $resort->contact_number,
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            // These must be ignored.
            'status' => 'inactive',
            'owner_id' => User::factory()->resortOwner()->create()->id,
            'is_featured' => true,
        ])->assertRedirect(route('admin.resorts.show', $resort));

        $original = $resort->owner_id;
        $resort->refresh();

        $this->assertSame('Tambobong', $resort->barangay);
        $this->assertSame(ResortStatus::Active, $resort->status);
        $this->assertSame($original, $resort->owner_id);
        $this->assertFalse($resort->is_featured);
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.edited_by_office', 'subject_id' => $resort->id]);
    }

    public function test_an_owner_cannot_manage_listings_through_the_office_screens(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();

        $this->actingAs($owner)->get(route('admin.resorts.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.resorts.edit', $resort))->assertForbidden();
        $this->actingAs($owner)->patch(route('admin.resorts.feature', $resort))->assertForbidden();
        $this->actingAs($owner)->patch(route('admin.resorts.deactivate', $resort), ['review_remarks' => 'Let me hide a rival listing.'])->assertForbidden();

        $resort->refresh();
        $this->assertSame(ResortStatus::Active, $resort->status);
        $this->assertFalse($resort->is_featured);
    }

    public function test_the_office_page_explains_when_a_listing_is_hidden_by_its_owners_status(): void
    {
        $owner = User::factory()->resortOwner(\App\Enums\UserStatus::Inactive)->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();

        $this->actingAs($this->mto)->get(route('admin.resorts.show', $resort))
            ->assertOk()
            ->assertSee('Hidden because the owner account is not active');
    }
}
