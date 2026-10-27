<?php

namespace Tests\Feature\Owner;

use App\Enums\ResortStatus;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResortEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->create(['name' => 'Salt Breeze Beach Resort']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->resort->name,
            'description' => $this->resort->description,
            'address' => $this->resort->address,
            'barangay' => $this->resort->barangay,
            'latitude' => $this->resort->latitude,
            'longitude' => $this->resort->longitude,
            'contact_number' => $this->resort->contact_number,
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
        ], $overrides);
    }

    public function test_an_owner_can_edit_their_own_resort(): void
    {
        $this->actingAs($this->owner)->get(route('owner.resort.edit'))->assertOk()->assertSee('Salt Breeze Beach Resort');

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
            'name' => 'Salt Breeze Resort and Cottages',
            'contact_number' => '09998887777',
        ]))->assertRedirect(route('owner.resort.show'));

        $this->resort->refresh();
        $this->assertSame('Salt Breeze Resort and Cottages', $this->resort->name);
        $this->assertSame('09998887777', $this->resort->contact_number);
        $this->assertSame('salt-breeze-beach-resort', $this->resort->slug, 'The public address must not change on rename.');
    }

    public function test_editing_never_changes_the_status(): void
    {
        foreach ([ResortStatus::Pending, ResortStatus::Active, ResortStatus::Inactive] as $status) {
            $this->resort->forceFill(['status' => $status])->save();

            $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
                'status' => 'active',
                'is_featured' => true,
            ]));

            $this->resort->refresh();
            $this->assertSame($status, $this->resort->status);
            $this->assertFalse($this->resort->is_featured);
        }
    }

    public function test_changes_to_a_live_listing_are_recorded(): void
    {
        $this->resort->forceFill(['status' => ResortStatus::Active])->save();

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload(['name' => 'Renamed Resort']));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'resort.updated',
            'user_id' => $this->owner->id,
            'subject_id' => $this->resort->id,
        ]);
    }

    public function test_a_rejected_resort_stays_rejected_until_the_owner_resubmits(): void
    {
        $this->resort->forceFill([
            'status' => ResortStatus::Rejected,
            'review_remarks' => 'Please add a clearer map pin.',
        ])->save();

        $this->actingAs($this->owner)->get(route('owner.resort.show'))
            ->assertOk()
            ->assertSee('Please add a clearer map pin.')
            ->assertSee('Send for review again');

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload(['address' => 'Sitio Bolo']));
        $this->assertSame(ResortStatus::Rejected, $this->resort->fresh()->status);

        $this->actingAs($this->owner)->patch(route('owner.resort.resubmit'))
            ->assertRedirect(route('owner.resort.show'));

        $this->resort->refresh();
        $this->assertSame(ResortStatus::Pending, $this->resort->status);
        $this->assertNull($this->resort->reviewed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.resubmitted', 'subject_id' => $this->resort->id]);
    }

    public function test_only_a_rejected_resort_can_be_resubmitted(): void
    {
        foreach ([ResortStatus::Pending, ResortStatus::Active, ResortStatus::Inactive] as $status) {
            $this->resort->forceFill(['status' => $status])->save();

            $this->actingAs($this->owner)->patch(route('owner.resort.resubmit'))->assertForbidden();
            $this->assertSame($status, $this->resort->fresh()->status);
        }
    }

    public function test_an_owner_never_sees_or_edits_another_owners_resort(): void
    {
        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->create(['name' => 'Someone Else Resort']);

        $this->actingAs($this->owner)->get(route('owner.resort.show'))
            ->assertOk()
            ->assertSee('Salt Breeze Beach Resort')
            ->assertDontSee('Someone Else Resort');

        // Editing only ever touches the signed-in owner's own resort.
        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
            'name' => 'Hijacked Name',
            'id' => $otherResort->id,
            'resort_id' => $otherResort->id,
        ]));

        $this->assertSame('Someone Else Resort', $otherResort->fresh()->name);
        $this->assertSame('Hijacked Name', $this->resort->fresh()->name);
    }

    public function test_an_owner_cannot_approve_or_feature_their_own_resort(): void
    {
        $this->assertFalse($this->owner->can('review', $this->resort));
        $this->assertTrue(User::factory()->superAdmin()->create()->can('review', $this->resort));
    }

    public function test_replacing_the_cover_photo_removes_the_old_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
            'main_image' => UploadedFile::fake()->image('first.jpg', 1000, 700),
        ]));
        $first = $this->resort->fresh()->main_image;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
            'main_image' => UploadedFile::fake()->image('second.jpg', 1000, 700),
        ]));
        $second = $this->resort->fresh()->main_image;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_the_cover_photo_can_be_removed(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload([
            'main_image' => UploadedFile::fake()->image('cover.jpg', 1000, 700),
        ]));
        $path = $this->resort->fresh()->main_image;

        $this->actingAs($this->owner)->put(route('owner.resort.update'), $this->payload(['remove_main_image' => '1']));

        $this->assertNull($this->resort->fresh()->main_image);
        Storage::disk('public')->assertMissing($path);
    }
}
