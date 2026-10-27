<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create(['first_name' => 'Tourism', 'last_name' => 'Office']);
    }

    public function test_the_log_shows_what_happened_and_who_did_it(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['first_name' => 'Ana', 'last_name' => 'Mendoza']);

        $this->actingAs($this->mto)->patch(route('admin.owner-applications.approve', $owner));

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Approved the owner account of Ana Mendoza')
            ->assertSee('Owner approved')
            ->assertSee('Tourism Office')
            ->assertSee('Open record');
    }

    public function test_status_changes_record_what_they_changed_from_and_to(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($this->mto)->patch(route('admin.owner-applications.approve', $owner));

        $log = ActivityLog::query()->where('action', 'owner.approved')->sole();
        $this->assertSame(['from' => 'pending', 'to' => 'active'], $log->properties);
        $this->assertSame('user', $log->subject_type);
        $this->assertNotNull($log->ip_address);

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index'))
            ->assertSee('From:')
            ->assertSee('pending');
    }

    public function test_an_owner_application_is_recorded_when_it_is_submitted(): void
    {
        $this->post(route('register.owner'), [
            'first_name' => 'Ana',
            'last_name' => 'Mendoza',
            'email' => 'ana@example.test',
            'phone' => '09171234567',
            'password' => 'seaside-2030',
            'password_confirmation' => 'seaside-2030',
            'business_name' => 'Sunrise Cove Resort',
            'position' => 'Owner',
            'terms' => '1',
        ])->assertRedirect(route('register.owner.submitted'));

        $log = ActivityLog::query()->where('action', 'owner.applied')->sole();
        $applicant = User::query()->where('email', 'ana@example.test')->sole();

        $this->assertSame($applicant->id, $log->user_id, 'The applicant is named as the actor.');
        $this->assertSame($applicant->id, $log->subject_id);
        $this->assertStringContainsString('Sunrise Cove Resort', $log->description);
    }

    public function test_the_log_can_be_filtered(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['first_name' => 'Ana']);
        $this->actingAs($this->mto)->patch(route('admin.owner-applications.approve', $owner));

        $resort = Resort::factory()->for($owner, 'owner')->active()->create(['name' => 'Salt Breeze Resort']);
        $this->actingAs($this->mto)->patch(route('admin.resorts.deactivate', $resort), [
            'review_remarks' => 'Closed for repairs until further notice.',
        ]);

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['group' => 'resort']))
            ->assertSee('Salt Breeze Resort')->assertDontSee('Approved the owner account');

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['group' => 'owner']))
            ->assertSee('Approved the owner account')->assertDontSee('Took the resort');

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['q' => 'Salt Breeze']))
            ->assertSee('Salt Breeze Resort')->assertDontSee('Approved the owner account');

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['user' => $this->mto->id]))
            ->assertSee('Salt Breeze Resort');

        // A range that ends before anything happened shows nothing.
        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['to' => now()->subWeek()->toDateString()]))
            ->assertSee('Nothing recorded yet');
    }

    public function test_actions_by_owners_and_guests_are_recorded_too(): void
    {
        $booking = Booking::factory()->create();
        $owner = $booking->resort->owner;

        $this->actingAs($owner)->patch(route('owner.bookings.confirm', $booking));

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index', ['group' => 'booking']))
            ->assertOk()
            ->assertSee('Confirmed '.$booking->booking_reference)
            ->assertSee($owner->full_name);
    }

    public function test_the_log_survives_the_record_it_describes(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $resort = Resort::factory()->active()->create(['name' => 'Gone Resort']);

        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'resort.approved',
            'subject_type' => $resort->getMorphClass(),
            'subject_id' => $resort->id,
            'description' => 'Approved the resort Gone Resort',
        ]);

        $resort->delete();

        $this->actingAs($this->mto)->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Approved the resort Gone Resort')
            ->assertSee('Record no longer exists');

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
    }

    public function test_secrets_are_never_written_to_the_log(): void
    {
        app(\App\Services\ActivityLogger::class)->log('user.test', null, 'A test entry', [
            'password' => 'should-not-be-stored',
            'remember_token' => 'nor-this',
            'reason' => 'but this is fine',
        ]);

        $log = ActivityLog::query()->where('action', 'user.test')->sole();

        $this->assertSame(['reason' => 'but this is fine'], $log->properties);
    }

    public function test_the_log_cannot_be_edited_or_deleted_through_the_application(): void
    {
        // There is no route for changing a log entry at all.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.activity-logs.destroy'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.activity-logs.update'));

        $this->actingAs($this->mto)->delete('/admin/activity-logs/1')->assertNotFound();
    }

    public function test_only_the_office_can_read_the_log(): void
    {
        foreach ([User::factory()->create(), User::factory()->resortOwner()->create()] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.activity-logs.index'))->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('admin.activity-logs.index'))->assertRedirect(route('login'));
    }
}
