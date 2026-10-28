<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * EVERY role, driven off UserRole::cases() rather than a list.
     *
     * This test used to name three of them, and the controller picks the
     * page's layout with a match that has no default arm — so the three
     * it did not name threw UnhandledMatchError. barangay_official was
     * one: the barangay sidebar linked here, and an official who clicked
     * Profile got a blank 500 and no way to change their password.
     *
     * Reading the enum is the point. A role added to UserRole and
     * forgotten in the controller now fails here instead of in front of
     * whoever the account was made for.
     */
    public function test_every_role_sees_its_own_profile_page(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create([
                'role' => $role,

                // Officials speak for one barangay and the page shows it.
                'barangay' => $role === UserRole::BarangayOfficial ? 'Malacapas' : null,
            ]);

            $this->actingAs($user)
                ->get(route('profile.edit'))
                ->assertOk()
                ->assertSee('Account details');
        }
    }

    public function test_a_user_can_update_their_details(): void
    {
        $tourist = User::factory()->create(['first_name' => 'Bea', 'home_city' => 'Manila']);

        $this->actingAs($tourist)->put(route('profile.update'), [
            'first_name' => 'Beatriz',
            'last_name' => $tourist->last_name,
            'email' => $tourist->email,
            'phone' => '0917 000 1111',
            'home_city' => 'Dagupan City',
            'home_country' => 'Philippines',
        ])->assertRedirect(route('profile.edit'));

        $tourist->refresh();
        $this->assertSame('Beatriz', $tourist->first_name);
        $this->assertSame('09170001111', $tourist->phone);
        $this->assertSame('Dagupan City', $tourist->home_city);
    }

    public function test_role_and_status_cannot_be_changed_through_the_profile(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->put(route('profile.update'), [
            'first_name' => $tourist->first_name,
            'last_name' => $tourist->last_name,
            'email' => $tourist->email,
            'role' => 'super_admin',
            'status' => 'inactive',
        ])->assertRedirect();

        $tourist->refresh();
        $this->assertSame(UserRole::Tourist, $tourist->role);
        $this->assertSame(UserStatus::Active, $tourist->status);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $tourist = User::factory()->create(['email' => 'old@example.test', 'password' => 'secret-password-1']);

        $payload = [
            'first_name' => $tourist->first_name,
            'last_name' => $tourist->last_name,
            'email' => 'new@example.test',
        ];

        $this->actingAs($tourist)->put(route('profile.update'), $payload)
            ->assertSessionHasErrors('current_password');
        $this->assertSame('old@example.test', $tourist->fresh()->email);

        $this->actingAs($tourist)->put(route('profile.update'), $payload + ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($tourist)->put(route('profile.update'), $payload + ['current_password' => 'secret-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', $tourist->fresh()->email);
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $this->actingAs($tourist)->put(route('profile.update'), [
            'first_name' => $tourist->first_name,
            'last_name' => $tourist->last_name,
            'email' => 'taken@example.test',
            'current_password' => 'secret-password-1',
        ])->assertSessionHasErrors('email');
    }

    public function test_an_owner_must_keep_a_contact_number(): void
    {
        $owner = User::factory()->resortOwner()->create();

        $this->actingAs($owner)->put(route('profile.update'), [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'email' => $owner->email,
            'phone' => null,
        ])->assertSessionHasErrors('phone');
    }

    public function test_changing_the_password(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $this->actingAs($tourist)->put(route('profile.password'), [
            'current_password' => 'secret-password-1',
            'password' => 'brand-new-2030',
            'password_confirmation' => 'brand-new-2030',
        ])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors('password');

        $this->assertTrue(Hash::check('brand-new-2030', $tourist->fresh()->password));
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $this->actingAs($tourist)->put(route('profile.password'), [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-2030',
            'password_confirmation' => 'brand-new-2030',
        ])->assertSessionHasErrors('current_password', null, 'password');

        $this->assertTrue(Hash::check('secret-password-1', $tourist->fresh()->password));
    }

    public function test_a_weak_or_unconfirmed_new_password_is_refused(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $this->actingAs($tourist)->put(route('profile.password'), [
            'current_password' => 'secret-password-1',
            'password' => 'shorty1',
            'password_confirmation' => 'shorty1',
        ])->assertSessionHasErrors('password', null, 'password');

        $this->actingAs($tourist)->put(route('profile.password'), [
            'current_password' => 'secret-password-1',
            'password' => 'brand-new-2030',
            'password_confirmation' => 'different-2030',
        ])->assertSessionHasErrors('password', null, 'password');
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        config(['session.driver' => 'database']);

        $tourist = User::factory()->create(['password' => 'secret-password-1']);
        $other = User::factory()->create();

        DB::table('sessions')->insert([
            ['id' => 'other-device-of-this-user', 'user_id' => $tourist->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'phone', 'payload' => '', 'last_activity' => time()],
            ['id' => 'session-of-another-user', 'user_id' => $other->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'phone', 'payload' => '', 'last_activity' => time()],
        ]);

        $oldRememberToken = $tourist->remember_token;

        $this->actingAs($tourist)->put(route('profile.password'), [
            'current_password' => 'secret-password-1',
            'password' => 'brand-new-2030',
            'password_confirmation' => 'brand-new-2030',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-of-this-user']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-of-another-user']);
        $this->assertNotSame($oldRememberToken, $tourist->fresh()->remember_token);
    }

    public function test_guests_cannot_reach_the_profile(): void
    {
        $this->put(route('profile.update'), [])->assertRedirect(route('login'));
        $this->put(route('profile.password'), [])->assertRedirect(route('login'));
    }
}
