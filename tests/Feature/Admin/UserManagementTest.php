<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $mto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mto = User::factory()->superAdmin()->create(['first_name' => 'Tourism', 'last_name' => 'Office']);
    }

    public function test_accounts_can_be_searched_and_filtered(): void
    {
        User::factory()->create(['first_name' => 'Bea', 'last_name' => 'Santos', 'email' => 'bea@example.test']);
        User::factory()->resortOwner()->create(['first_name' => 'Carmela', 'last_name' => 'Reyes']);
        User::factory()->inactive()->create(['first_name' => 'Rico', 'last_name' => 'Bautista']);

        $this->actingAs($this->mto)->get(route('admin.users.index'))
            ->assertOk()->assertSee('Bea')->assertSee('Carmela')->assertSee('Rico');

        $this->actingAs($this->mto)->get(route('admin.users.index', ['role' => 'resort_owner']))
            ->assertSee('Carmela')->assertDontSee('Bea');

        $this->actingAs($this->mto)->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertSee('Rico')->assertDontSee('Carmela');

        $this->actingAs($this->mto)->get(route('admin.users.index', ['q' => 'bea@example']))
            ->assertSee('Bea')->assertDontSee('Rico');
    }

    public function test_deactivating_signs_the_person_out_everywhere(): void
    {
        config(['session.driver' => 'database']);
        $tourist = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'their-phone', 'user_id' => $tourist->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'phone', 'payload' => '', 'last_activity' => time(),
        ]);

        $this->actingAs($this->mto)
            ->patch(route('admin.users.deactivate', $tourist), ['reason' => 'Duplicate account'])
            ->assertRedirect();

        $this->assertSame(UserStatus::Inactive, $tourist->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['id' => 'their-phone']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.deactivated', 'subject_id' => $tourist->id]);
        $this->assertSame('Duplicate account', \App\Models\ActivityLog::sole()->properties['reason']);
    }

    public function test_deactivating_an_owner_hides_their_resort_without_changing_its_status(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();

        $this->assertSame(1, Resort::publiclyVisible()->count());

        $this->actingAs($this->mto)->patch(route('admin.users.deactivate', $owner));

        $this->assertSame(0, Resort::publiclyVisible()->count());
        $this->assertSame(\App\Enums\ResortStatus::Active, $resort->fresh()->status);

        // Reactivating brings the listing straight back.
        $this->actingAs($this->mto)->patch(route('admin.users.activate', $owner));
        $this->assertSame(1, Resort::publiclyVisible()->count());
        $this->assertSame(UserStatus::Active, $owner->fresh()->status);
    }

    public function test_the_office_cannot_deactivate_itself(): void
    {
        $this->actingAs($this->mto)
            ->patch(route('admin.users.deactivate', $this->mto))
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $this->mto->fresh()->status);
    }

    public function test_one_office_account_cannot_deactivate_another(): void
    {
        $colleague = User::factory()->superAdmin()->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.users.deactivate', $colleague))
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $colleague->fresh()->status);
    }

    public function test_a_pending_owner_cannot_be_activated_from_the_accounts_page(): void
    {
        // Pending applications are approved through the review screen instead.
        $pending = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($this->mto)
            ->patch(route('admin.users.activate', $pending))
            ->assertForbidden();

        $this->assertSame(UserStatus::Pending, $pending->fresh()->status);
    }

    public function test_the_account_page_shows_history_and_the_right_action(): void
    {
        $owner = User::factory()->resortOwner()->create(['first_name' => 'Carmela']);

        $this->actingAs($this->mto)->patch(route('admin.users.deactivate', $owner));

        $this->actingAs($this->mto)->get(route('admin.users.show', $owner))
            ->assertOk()
            ->assertSee('Carmela')
            ->assertSee('Reactivate account')
            ->assertSee('Deactivated the account of');
    }

    public function test_the_owners_list_shows_each_owner_and_their_listing(): void
    {
        $owner = User::factory()->resortOwner()->create(['first_name' => 'Carmela']);
        Resort::factory()->for($owner, 'owner')->create(['name' => 'Salt Breeze Resort']);
        User::factory()->resortOwner(UserStatus::Pending)->create(['first_name' => 'Ana']);

        $this->actingAs($this->mto)->get(route('admin.owners.index'))
            ->assertOk()
            ->assertSee('Carmela')
            ->assertSee('Salt Breeze Resort')
            ->assertSee('Ana');

        $this->actingAs($this->mto)->get(route('admin.owners.index', ['status' => 'pending']))
            ->assertSee('Ana')
            ->assertDontSee('Carmela');
    }

    public function test_nobody_else_may_manage_accounts(): void
    {
        $tourist = User::factory()->create();
        $owner = User::factory()->resortOwner()->create();

        foreach ([$tourist, $owner] as $intruder) {
            $this->actingAs($intruder)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($intruder)->get(route('admin.owners.index'))->assertForbidden();
            $this->actingAs($intruder)->patch(route('admin.users.deactivate', $tourist))->assertForbidden();
        }

        $this->assertSame(UserStatus::Active, $tourist->fresh()->status);
    }

    public function test_the_sidebar_shows_how_much_work_is_waiting(): void
    {
        User::factory()->count(2)->resortOwner(UserStatus::Pending)->create();
        Resort::factory()->count(3)->create();

        $response = $this->actingAs($this->mto)->get(route('admin.dashboard'))->assertOk();

        $response->assertSeeInOrder(['Owner applications', '2']);
        $response->assertSeeInOrder(['Resort applications', '3']);
    }
}
