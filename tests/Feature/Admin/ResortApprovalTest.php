<?php

namespace Tests\Feature\Admin;

use App\Enums\ResortStatus;
use App\Enums\UserStatus;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResortApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create();
    }

    /** A listing that passes every required check. */
    private function readyResort(array $attributes = []): Resort
    {
        return Resort::factory()->create(array_merge([
            'main_image' => 'resorts/1/cover/photo.jpg',
            'latitude' => 15.99,
            'longitude' => 119.88,
        ], $attributes));
    }

    public function test_the_queue_lists_listings_waiting_for_review(): void
    {
        $this->readyResort(['name' => 'Coral Point Resort']);
        Resort::factory()->active()->create(['name' => 'Already Live Resort']);
        Resort::factory()->rejected()->create(['name' => 'Sent Back Resort']);

        $this->actingAs($this->mto)->get(route('admin.resort-applications.index'))
            ->assertOk()
            ->assertSee('Coral Point Resort')
            ->assertDontSee('Already Live Resort')
            ->assertDontSee('Sent Back Resort');

        $this->actingAs($this->mto)->get(route('admin.resort-applications.index', ['status' => 'rejected']))
            ->assertSee('Sent Back Resort')
            ->assertDontSee('Coral Point Resort');
    }

    public function test_the_review_page_shows_the_listing_owner_and_checklist(): void
    {
        $owner = User::factory()->resortOwner()->create(['first_name' => 'Joel', 'last_name' => 'Castro']);
        $resort = $this->readyResort(['owner_id' => $owner->id, 'name' => 'Coral Point Resort']);
        Room::factory()->for($resort)->create(['name' => 'Deluxe Room']);

        $this->actingAs($this->mto)->get(route('admin.resort-applications.show', $resort))
            ->assertOk()
            ->assertSee('Coral Point Resort')
            ->assertSee('Joel')
            ->assertSee('Deluxe Room')
            ->assertSee('The owner account is active')
            ->assertSee('Approve and publish');
    }

    public function test_approving_publishes_the_listing(): void
    {
        $resort = $this->readyResort(['name' => 'Coral Point Resort']);
        Room::factory()->for($resort)->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.approve', $resort))
            ->assertRedirect(route('admin.resort-applications.index'))
            ->assertSessionHas('success');

        $resort->refresh();
        $this->assertSame(ResortStatus::Active, $resort->status);
        $this->assertSame($this->mto->id, $resort->reviewed_by);
        $this->assertNotNull($resort->reviewed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.approved', 'subject_id' => $resort->id]);

        // It is now public, and its rooms are bookable.
        $this->post(route('logout'));
        $this->get(route('resorts.index'))->assertOk()->assertSee('Coral Point Resort');
        $this->get(route('resorts.show', $resort))->assertOk();
        $this->assertSame(1, Room::bookable()->count());
    }

    public function test_a_listing_without_a_cover_photo_cannot_be_approved(): void
    {
        $resort = Resort::factory()->create(['main_image' => null]);

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.approve', $resort))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);
    }

    public function test_a_listing_with_a_pin_outside_dasol_cannot_be_approved(): void
    {
        $resort = $this->readyResort(['latitude' => 14.6, 'longitude' => 121.0]);

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.approve', $resort))
            ->assertSessionHas('error');

        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);
    }

    public function test_a_listing_cannot_be_approved_while_its_owner_is_not_active(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Inactive)->create();
        $resort = $this->readyResort(['owner_id' => $owner->id]);

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.approve', $resort))
            ->assertForbidden();

        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);
    }

    public function test_sending_a_listing_back_requires_notes(): void
    {
        $resort = $this->readyResort();
        $owner = $resort->owner;

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.reject', $resort), ['review_remarks' => ''])
            ->assertSessionHasErrors('review_remarks');
        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);

        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.reject', $resort), ['review_remarks' => 'The map pin is in the wrong barangay.'])
            ->assertRedirect(route('admin.resort-applications.index'));

        $resort->refresh();
        $this->assertSame(ResortStatus::Rejected, $resort->status);
        $this->assertSame('The map pin is in the wrong barangay.', $resort->review_remarks);
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.rejected', 'subject_id' => $resort->id]);

        // The owner sees the reason and can send it back for review.
        $this->actingAs($owner)->get(route('owner.resort.show'))
            ->assertOk()
            ->assertSee('The map pin is in the wrong barangay.')
            ->assertSee('Send for review again');
    }

    public function test_an_approved_listing_cannot_be_approved_or_rejected_again(): void
    {
        $resort = Resort::factory()->active()->create();

        $this->actingAs($this->mto)->patch(route('admin.resort-applications.approve', $resort))->assertForbidden();
        $this->actingAs($this->mto)
            ->patch(route('admin.resort-applications.reject', $resort), ['review_remarks' => 'Changed my mind about this.'])
            ->assertForbidden();

        $this->assertSame(ResortStatus::Active, $resort->fresh()->status);
    }

    public function test_owners_and_tourists_cannot_reach_the_review_screens(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = $this->readyResort(['owner_id' => $owner->id]);

        foreach ([$owner, User::factory()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.resort-applications.index'))->assertForbidden();
            $this->actingAs($intruder)->get(route('admin.resort-applications.show', $resort))->assertForbidden();
            $this->actingAs($intruder)->patch(route('admin.resort-applications.approve', $resort))->assertForbidden();
        }

        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);
    }

    public function test_approval_rejects_get_requests(): void
    {
        $resort = $this->readyResort();

        $this->actingAs($this->mto)->get('/admin/resort-applications/'.$resort->id.'/approve')->assertStatus(405);
        $this->assertSame(ResortStatus::Pending, $resort->fresh()->status);
    }
}
