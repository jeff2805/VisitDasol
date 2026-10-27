<?php

namespace Tests\Feature\EnvironmentalFee;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\EnvironmentalFeeCollector;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The office creating and managing the people who work the fee.
 *
 * THREE THINGS THESE TESTS EXIST TO PROTECT:
 *
 *   1. ONLY THE OFFICE MAY MINT AN ACCOUNT. An account that can take
 *      municipal money at a barrier is handed out by the municipality.
 *      A collector able to create one could create a second collector,
 *      post it to their own gate, and write receipts under a name
 *      nobody has heard of.
 *
 *   2. NOBODY MAY PROMOTE THEMSELVES. Being able to edit your own role
 *      is the whole of privilege escalation in one step.
 *
 *   3. NO PASSWORD IS EVER CHOSEN FOR SOMEBODY ELSE, STORED IN THE
 *      CLEAR, OR EMAILED. The account starts with a random secret
 *      nobody holds and the person sets their own through an expiring
 *      link.
 */
class EnvironmentalFeeStaffTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    private User $collector;

    private User $cashier;

    private User $owner;

    private User $tourist;

    private EnvironmentalFeeCheckpoint $checkpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-15 09:00:00');

        $this->mto = User::factory()->superAdmin()->create();
        $this->collector = User::factory()->create(['role' => UserRole::FeeCollector]);
        $this->cashier = User::factory()->create(['role' => UserRole::Cashier]);
        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->checkpoint = $this->makeCheckpoint('Tambobong beach road');
    }

    private function makeCheckpoint(string $name, bool $active = true): EnvironmentalFeeCheckpoint
    {
        $checkpoint = new EnvironmentalFeeCheckpoint([
            'name' => $name,
            'barangay' => 'Tambobong',
            'latitude' => 15.9327,
            'longitude' => 119.7823,
        ]);

        $checkpoint->createdBy()->associate($this->mto);
        $checkpoint->active = $active;
        $checkpoint->save();

        return $checkpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rosa',
            'last_name' => 'Delfin',
            'email' => 'rosa.delfin@dasol.test',
            'phone' => '09171234567',
            'employee_id' => 'MTO-0042',
            'role' => UserRole::FeeCollector->value,
            'checkpoints' => [$this->checkpoint->id],
        ], $overrides);
    }

    /* Who may reach the screen at all -------------------------------------- */

    public function test_a_tourist_is_refused_every_staff_screen(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('admin.environmental-fee.staff.index'))->assertForbidden();

        $this->actingAs($this->tourist)
            ->get(route('admin.environmental-fee.staff.create'))->assertForbidden();

        $this->actingAs($this->tourist)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rosa.delfin@dasol.test']);
    }

    public function test_a_resort_owner_is_refused_every_staff_screen(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.environmental-fee.staff.index'))->assertForbidden();

        $this->actingAs($this->owner)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rosa.delfin@dasol.test']);
    }

    /** The one that matters most: staff cannot mint more staff. */
    public function test_a_collector_cannot_create_staff(): void
    {
        $this->actingAs($this->collector)
            ->get(route('admin.environmental-fee.staff.index'))->assertForbidden();

        $this->actingAs($this->collector)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rosa.delfin@dasol.test']);
    }

    public function test_a_cashier_cannot_create_staff(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.staff.index'))->assertForbidden();

        $this->actingAs($this->cashier)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rosa.delfin@dasol.test']);
    }

    /**
     * The role closest to fee staff, and the easiest to let in by
     * accident: a barangay official may collect at a gate they are
     * assigned to, but they are not the office.
     */
    public function test_a_barangay_official_cannot_create_staff(): void
    {
        $official = User::factory()->create([
            'role' => UserRole::BarangayOfficial,
            'barangay' => 'Tambobong',
        ]);

        $this->actingAs($official)
            ->get(route('admin.environmental-fee.staff.index'))->assertForbidden();

        $this->actingAs($official)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'rosa.delfin@dasol.test']);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.environmental-fee.staff.index'))->assertRedirect(route('login'));
    }

    public function test_the_office_can_open_the_staff_screen(): void
    {
        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.staff.index'))
            ->assertOk()
            ->assertSee('Fee staff');
    }

    /* Creating an account --------------------------------------------------- */

    public function test_the_office_creates_a_collector_and_posts_them_to_a_gate(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.environmental-fee.staff.index'));

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        $this->assertSame(UserRole::FeeCollector, $staff->role);
        $this->assertSame(UserStatus::Active, $staff->status);
        $this->assertSame('MTO-0042', $staff->employee_id);

        // Not confirmed: setting a password through the link is what
        // proves the address was typed correctly.
        $this->assertNull($staff->email_verified_at);

        $this->assertDatabaseHas('environmental_fee_collectors', [
            'user_id' => $staff->id,
            'checkpoint_id' => $this->checkpoint->id,
            'active' => true,
            'assigned_by' => $this->mto->id,
        ]);
    }

    /**
     * THE PASSWORD RULE. Nothing the office typed, nothing anybody
     * holds, and nothing sent by email but a link.
     */
    public function test_no_password_is_chosen_stored_or_emailed(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                // Hopeful, and must be ignored: the office does not get
                // to choose somebody else's password.
                'password' => 'TheOfficeChoseThis1',
                'password_confirmation' => 'TheOfficeChoseThis1',
            ]))
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        $this->assertFalse(Hash::check('TheOfficeChoseThis1', $staff->password));

        // Stored hashed, never in the clear.
        $this->assertStringStartsWith('$2y$', $staff->password);

        // And an expiring link is what actually goes out.
        Notification::assertSentTo($staff, ResetPassword::class);
    }

    public function test_creating_staff_sends_the_invitation(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHas('success');

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        Notification::assertSentTo($staff, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $staff->email]);
    }

    public function test_the_invitation_can_be_sent_again(): void
    {
        Notification::fake();

        $staff = User::factory()->create([
            'role' => UserRole::FeeCollector,
            'email_verified_at' => null,
        ]);

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.invite', $staff))
            ->assertSessionHas('success');

        Notification::assertSentTo($staff, ResetPassword::class);
    }

    /* Validation ------------------------------------------------------------ */

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                'email' => $this->tourist->email,
            ]))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', $this->tourist->email)->count());
    }

    public function test_an_employee_number_already_in_use_is_refused(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                'email' => 'someone.else@dasol.test',
            ]))
            ->assertSessionHasErrors('employee_id');
    }

    /** Optional, because the office may not have one to hand. */
    public function test_the_employee_number_may_be_left_out(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form(['employee_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(
            User::query()->where('email', 'rosa.delfin@dasol.test')->sole()->employee_id
        );
    }

    /* Privilege escalation --------------------------------------------------- */

    /**
     * A staff screen that could mint a resort owner would be a way to
     * create a business account nobody applied for.
     */
    public function test_only_the_three_fee_roles_may_be_assigned(): void
    {
        foreach (['tourist', 'resort_owner', 'barangay_official', 'wizard'] as $role) {
            $this->actingAs($this->mto)
                ->post(route('admin.environmental-fee.staff.store'), $this->form([
                    'email' => $role.'@dasol.test',
                    'role' => $role,
                    'checkpoints' => [],
                ]))
                ->assertSessionHasErrors('role');

            $this->assertDatabaseMissing('users', ['email' => $role.'@dasol.test']);
        }
    }

    /**
     * Being able to edit your own role is privilege escalation in one
     * step. The office cannot demote itself either, which also stops
     * the municipality locking itself out by accident.
     */
    public function test_nobody_may_edit_their_own_account_here(): void
    {
        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.staff.edit', $this->mto))
            ->assertForbidden();

        $this->actingAs($this->mto)
            ->put(route('admin.environmental-fee.staff.update', $this->mto), $this->form([
                'email' => $this->mto->email,
                'role' => UserRole::SuperAdmin->value,
                'checkpoints' => [],
            ]))
            ->assertForbidden();
    }

    public function test_a_collector_cannot_promote_themselves(): void
    {
        $this->actingAs($this->collector)
            ->put(route('admin.environmental-fee.staff.update', $this->collector), $this->form([
                'email' => $this->collector->email,
                'role' => UserRole::SuperAdmin->value,
                'checkpoints' => [],
            ]))
            ->assertForbidden();

        $this->assertSame(UserRole::FeeCollector, $this->collector->refresh()->role);
    }

    public function test_a_non_fee_account_cannot_be_edited_from_this_screen(): void
    {
        // The tourist's account is managed from Accounts, not here.
        $this->actingAs($this->mto)
            ->get(route('admin.environmental-fee.staff.edit', $this->tourist))
            ->assertForbidden();
    }

    /* Checkpoints ------------------------------------------------------------ */

    public function test_only_a_collector_may_be_put_on_a_checkpoint(): void
    {
        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                'role' => UserRole::Cashier->value,
                'checkpoints' => [$this->checkpoint->id],
            ]))
            ->assertSessionHasErrors('checkpoints');
    }

    public function test_a_switched_off_checkpoint_cannot_be_staffed(): void
    {
        $closed = $this->makeCheckpoint('Closed gate', active: false);

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                'checkpoints' => [$closed->id],
            ]))
            ->assertSessionHasErrors('checkpoints');
    }

    public function test_a_checkpoint_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form([
                'checkpoints' => [999999],
            ]))
            ->assertSessionHasErrors('checkpoints.0');
    }

    /**
     * Taking somebody off a gate switches the row off rather than
     * deleting it: a collection points at its collector, and who was
     * posted where on a given day is how a disputed receipt is settled.
     */
    public function test_moving_somebody_off_a_gate_keeps_the_record(): void
    {
        Notification::fake();

        $second = $this->makeCheckpoint('Uli road');

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        $this->actingAs($this->mto)
            ->put(route('admin.environmental-fee.staff.update', $staff), $this->form([
                'checkpoints' => [$second->id],
            ]))
            ->assertSessionHasNoErrors();

        // The old posting is still on file, switched off.
        $this->assertDatabaseHas('environmental_fee_collectors', [
            'user_id' => $staff->id,
            'checkpoint_id' => $this->checkpoint->id,
            'active' => false,
        ]);

        $this->assertDatabaseHas('environmental_fee_collectors', [
            'user_id' => $staff->id,
            'checkpoint_id' => $second->id,
            'active' => true,
        ]);
    }

    /* The audit trail --------------------------------------------------------- */

    public function test_creating_and_inviting_are_written_down(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        foreach (['environmental_fee.staff_created', 'environmental_fee.staff_invited'] as $action) {
            $this->assertDatabaseHas('activity_logs', [
                'action' => $action,
                'subject_type' => 'user',
                'subject_id' => $staff->id,
                'user_id' => $this->mto->id,
            ]);
        }
    }

    public function test_a_change_of_role_is_written_down(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        $this->actingAs($this->mto)
            ->put(route('admin.environmental-fee.staff.update', $staff), $this->form([
                'role' => UserRole::Cashier->value,
                'checkpoints' => [],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'environmental_fee.staff_role_changed',
            'subject_type' => 'user',
            'subject_id' => $staff->id,
            'user_id' => $this->mto->id,
        ]);
    }

    /** A token in the audit trail would be a way to take an account over. */
    public function test_the_audit_trail_never_records_a_token_or_a_password(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $logs = \App\Models\ActivityLog::query()
            ->whereIn('action', ['environmental_fee.staff_created', 'environmental_fee.staff_invited'])
            ->get();

        foreach ($logs as $log) {
            $properties = is_string($log->properties)
                ? json_decode($log->properties, true)
                : (array) $log->properties;

            foreach (['token', 'password', 'secret'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $properties);
            }
        }
    }

    /* Deactivation ------------------------------------------------------------ */

    /**
     * Deactivating must stop somebody getting in, and must not take
     * their work with them.
     */
    public function test_a_deactivated_collector_is_locked_out_but_their_records_stay(): void
    {
        Notification::fake();

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.store'), $this->form())
            ->assertSessionHasNoErrors();

        $staff = User::query()->where('email', 'rosa.delfin@dasol.test')->sole();

        /*
         * Setting a password through the invitation link is what
         * confirms the address, and the fee routes are behind
         * "verified". Done here by hand so this test is about
         * deactivation rather than about the invitation.
         */
        $staff->forceFill(['email_verified_at' => now()])->save();

        // They can reach their own screen while active.
        $this->actingAs($staff)->get(route('environmental-fee.index'))->assertOk();

        $this->actingAs($this->mto)
            ->patch(route('admin.users.deactivate', $staff), ['reason' => 'Left the service.'])
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertSame(UserStatus::Inactive, $staff->status);

        /*
         * The very next request on a live session is bounced by
         * EnsureAccountIsActive, which signs them out as it goes.
         */
        $this->actingAs($staff)
            ->get(route('environmental-fee.index'))
            ->assertRedirect(route('login'));

        // Their posting, and the account every receipt points at, stay.
        $this->assertDatabaseHas('environmental_fee_collectors', [
            'user_id' => $staff->id,
            'checkpoint_id' => $this->checkpoint->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    /**
     * Their live sessions are dropped, not left to expire.
     *
     * The suite runs on the array session driver, and AccountManager
     * only sweeps the table when sessions live in the database — which
     * is how this deployment is configured. Pointed at the real driver
     * here so the sweep is actually exercised rather than skipped.
     */
    public function test_deactivating_ends_any_session_they_had_open(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'sessions']);

        $staff = User::factory()->create(['role' => UserRole::FeeCollector]);

        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => 'staff-session-id',
            'user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $this->actingAs($this->mto)
            ->patch(route('admin.users.deactivate', $staff), ['reason' => 'Left the service.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);
    }

    /** A switched-off account is not handed a way back in. */
    public function test_a_deactivated_account_cannot_be_invited(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::FeeCollector,
            'status' => UserStatus::Inactive,
        ]);

        $this->actingAs($this->mto)
            ->post(route('admin.environmental-fee.staff.invite', $staff))
            ->assertForbidden();
    }

    /* What each post may actually do -------------------------------------------- */

    public function test_a_cashier_may_count_handovers_but_not_configure_the_fee(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.remittances.index'))->assertOk();

        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.reports'))->assertOk();

        // And nothing else under the fee prefix.
        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.index'))->assertForbidden();

        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.create'))->assertForbidden();

        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.checkpoints.index'))->assertForbidden();

        $this->actingAs($this->cashier)
            ->get(route('admin.environmental-fee.gcash.edit'))->assertForbidden();
    }

    /** A cashier must not reach the rest of the office's work. */
    public function test_a_cashier_cannot_reach_the_other_admin_screens(): void
    {
        foreach ([
            'admin.dashboard', 'admin.users.index', 'admin.resorts.index',
            'admin.bookings.index', 'admin.activity-logs.index',
        ] as $route) {
            $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
        }
    }

    public function test_a_collector_may_collect_but_not_count(): void
    {
        $this->actingAs($this->collector)
            ->get(route('environmental-fee.index'))->assertOk();

        $this->actingAs($this->collector)
            ->get(route('admin.environmental-fee.remittances.index'))->assertForbidden();
    }
}
