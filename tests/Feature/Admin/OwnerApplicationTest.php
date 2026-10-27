<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OwnerApplicationTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create();
    }

    public function test_the_queue_shows_pending_applications_only(): void
    {
        $pending = User::factory()->resortOwner(UserStatus::Pending)->create(['first_name' => 'Ana']);
        $rejected = User::factory()->resortOwner(UserStatus::Rejected)->create(['first_name' => 'Paolo']);
        $active = User::factory()->resortOwner()->create(['first_name' => 'Carmela']);

        $this->actingAs($this->mto)->get(route('admin.owner-applications.index'))
            ->assertOk()
            ->assertSee('Ana')
            ->assertDontSee('Paolo')
            ->assertDontSee('Carmela');

        $this->actingAs($this->mto)->get(route('admin.owner-applications.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertSee('Paolo')
            ->assertDontSee('Ana');
    }

    public function test_the_review_page_shows_the_application(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['first_name' => 'Ana', 'phone' => '09171234567']);
        $owner->ownerProfile->forceFill(['business_name' => 'Sunrise Cove Resort', 'business_permit_no' => 'BP-2030-1'])->save();

        $this->actingAs($this->mto)->get(route('admin.owner-applications.show', $owner))
            ->assertOk()
            ->assertSee('Sunrise Cove Resort')
            ->assertSee('09171234567')
            ->assertSee('BP-2030-1')
            ->assertSee('Approve application');
    }

    public function test_approving_lets_the_owner_sign_in(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['password' => 'secret-password-1']);

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.approve', $owner))
            ->assertRedirect(route('admin.owner-applications.index'))
            ->assertSessionHas('success');

        $owner->refresh();
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertSame($this->mto->id, $owner->ownerProfile->reviewed_by);
        $this->assertNotNull($owner->ownerProfile->reviewed_at);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'owner.approved',
            'user_id' => $this->mto->id,
            'subject_type' => 'user',
            'subject_id' => $owner->id,
        ]);
        $this->assertSame(['from' => 'pending', 'to' => 'active'], ActivityLog::sole()->properties);

        // The owner can now sign in and reach their dashboard.
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $owner->email, 'password' => 'secret-password-1']);
        $this->assertAuthenticatedAs($owner);
        $this->get(route('owner.dashboard'))->assertOk();
    }

    public function test_turning_down_an_application_requires_a_reason(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.reject', $owner), ['review_remarks' => ''])
            ->assertSessionHasErrors('review_remarks');

        $this->assertSame(UserStatus::Pending, $owner->fresh()->status);

        // The reason box is shown again, marked invalid, with what was typed.
        $this->actingAs($this->mto)->get(route('admin.owner-applications.show', $owner))
            ->assertSee('is-invalid', false)
            ->assertSee('Give the applicant a reason for the decision.');

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.reject', $owner), ['review_remarks' => 'The permit number could not be verified.'])
            ->assertRedirect(route('admin.owner-applications.index'));

        $owner->refresh();
        $this->assertSame(UserStatus::Rejected, $owner->status);
        $this->assertSame('The permit number could not be verified.', $owner->ownerProfile->review_remarks);
        $this->assertDatabaseHas('activity_logs', ['action' => 'owner.rejected', 'subject_id' => $owner->id]);
    }

    public function test_a_turned_down_applicant_cannot_sign_in_and_sees_the_reason(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['password' => 'secret-password-1']);

        $this->actingAs($this->mto)->patch(route('admin.owner-applications.reject', $owner), [
            'review_remarks' => 'The business permit was missing.',
        ]);
        $this->post(route('logout'));

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'secret-password-1']);

        $this->assertGuest();
        $this->assertStringContainsString('The business permit was missing.', session('error'));
    }

    public function test_an_application_can_be_reopened_after_being_turned_down(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Rejected)->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.reopen', $owner))
            ->assertRedirect(route('admin.owner-applications.show', $owner));

        $owner->refresh();
        $this->assertSame(UserStatus::Pending, $owner->status);
        $this->assertNull($owner->ownerProfile->reviewed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'owner.reopened', 'subject_id' => $owner->id]);
    }

    public function test_an_already_approved_application_cannot_be_approved_again(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Active)->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.approve', $owner))
            ->assertForbidden();

        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.reject', $owner), ['review_remarks' => 'Changed my mind about this one.'])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $owner->fresh()->status);
    }

    public function test_tourist_accounts_are_not_owner_applications(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($this->mto)->get(route('admin.owner-applications.show', $tourist))->assertForbidden();
        $this->actingAs($this->mto)->patch(route('admin.owner-applications.approve', $tourist))->assertForbidden();

        $this->assertTrue($tourist->fresh()->isTourist());
    }

    public function test_the_office_cannot_review_its_own_account(): void
    {
        $this->actingAs($this->mto)
            ->patch(route('admin.owner-applications.approve', $this->mto))
            ->assertForbidden();
    }

    public function test_the_permit_document_is_only_served_to_the_office(): void
    {
        Storage::fake('local');
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();
        $path = UploadedFile::fake()->create('permit.pdf', 40, 'application/pdf')->store('owner-documents/'.$owner->id, 'local');
        $owner->ownerProfile->forceFill(['permit_document_path' => $path])->save();

        $this->actingAs($this->mto)->get(route('admin.owner-applications.permit', $owner))->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.owner-applications.permit', $owner))
            ->assertForbidden();

        $this->post(route('logout'));
        $this->get(route('admin.owner-applications.permit', $owner))->assertRedirect(route('login'));
    }

    public function test_a_missing_permit_gives_a_not_found_response(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($this->mto)->get(route('admin.owner-applications.permit', $owner))->assertNotFound();
    }

    public function test_only_the_office_may_open_these_pages(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.owner-applications.index'))->assertForbidden();
            $this->actingAs($intruder)->patch(route('admin.owner-applications.approve', $owner))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('admin.owner-applications.index'))->assertRedirect(route('login'));
        $this->assertSame(UserStatus::Pending, $owner->fresh()->status);
    }

    public function test_status_changes_reject_get_requests(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($this->mto)->get('/admin/owner-applications/'.$owner->id.'/approve')->assertStatus(405);
        $this->assertSame(UserStatus::Pending, $owner->fresh()->status);
    }
}
