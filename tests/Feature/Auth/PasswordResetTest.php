<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_links_to_password_reset(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_reset_link_is_emailed_to_a_registered_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'bea@example.test']);

        $this->post(route('password.email'), ['email' => 'BEA@example.test'])
            ->assertRedirect()
            ->assertSessionHas('success', PasswordResetLinkController::SENT_MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_email_is_branded_and_links_to_the_reset_page(): void
    {
        Notification::fake();
        $user = User::factory()->create(['first_name' => 'Bea', 'email' => 'bea@example.test']);

        $this->post(route('password.email'), ['email' => 'bea@example.test']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Reset your Visit Dasol password', $mail->subject);
            $this->assertSame('Hello Bea,', $mail->greeting);
            $this->assertStringContainsString('/reset-password/', $mail->actionUrl);
            $this->assertStringContainsString('email=bea%40example.test', $mail->actionUrl);

            return true;
        });
    }

    public function test_an_unknown_address_gets_exactly_the_same_answer(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->post(route('password.email'), ['email' => 'known@example.test']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

        // No way to tell from the response whether the account exists.
        $known->assertSessionHas('success', PasswordResetLinkController::SENT_MESSAGE);
        $unknown->assertSessionHas('success', PasswordResetLinkController::SENT_MESSAGE);
        $unknown->assertSessionHasNoErrors();

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_valid_link_sets_the_new_password_and_signs_out_other_devices(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'bea@example.test', 'password' => 'old-password-1']);
        $oldRememberToken = $user->remember_token;

        DB::table('sessions')->insert([
            'id' => 'stolen-session', 'user_id' => $user->id, 'ip_address' => '203.0.113.9',
            'user_agent' => 'elsewhere', 'payload' => '', 'last_activity' => time(),
        ]);

        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => 'bea@example.test']))
            ->assertOk()->assertSee('Choose a new password');

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'bea@example.test',
            'password' => 'brand-new-2030',
            'password_confirmation' => 'brand-new-2030',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-2030', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.password_reset', 'subject_id' => $user->id]);

        // Not signed in automatically.
        $this->assertGuest();
    }

    public function test_a_link_works_only_once(): void
    {
        $user = User::factory()->create(['email' => 'bea@example.test']);
        $token = Password::createToken($user);

        $payload = ['token' => $token, 'email' => 'bea@example.test', 'password' => 'brand-new-2030', 'password_confirmation' => 'brand-new-2030'];

        $this->post(route('password.store'), $payload)->assertRedirect(route('login'));
        $this->post(route('password.store'), array_merge($payload, ['password' => 'second-try-2030', 'password_confirmation' => 'second-try-2030']))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('brand-new-2030', $user->fresh()->password));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'bea@example.test', 'password' => 'old-password-1']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.test',
            'password' => 'brand-new-2030', 'password_confirmation' => 'brand-new-2030',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_a_made_up_token_or_someone_elses_email_is_refused(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.test', 'password' => 'old-password-1']);
        $attacker = User::factory()->create(['email' => 'attacker@example.test']);
        $attackerToken = Password::createToken($attacker);

        // A guessed token.
        $this->post(route('password.store'), [
            'token' => 'guessed-token', 'email' => 'victim@example.test',
            'password' => 'taken-over-2030', 'password_confirmation' => 'taken-over-2030',
        ])->assertSessionHasErrors('email');

        // A real token, but for a different account.
        $this->post(route('password.store'), [
            'token' => $attackerToken, 'email' => 'victim@example.test',
            'password' => 'taken-over-2030', 'password_confirmation' => 'taken-over-2030',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-1', $victim->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_rules(): void
    {
        $user = User::factory()->create(['email' => 'bea@example.test']);
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.test',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.test',
            'password' => 'brand-new-2030', 'password_confirmation' => 'different-2030',
        ])->assertSessionHasErrors('password');
    }

    public function test_resetting_does_not_let_an_unapproved_owner_in(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create(['email' => 'owner@example.test']);
        $token = Password::createToken($owner);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'owner@example.test',
            'password' => 'brand-new-2030', 'password_confirmation' => 'brand-new-2030',
        ])->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => 'owner@example.test', 'password' => 'brand-new-2030']);

        $this->assertGuest();
        $this->assertStringContainsString('still being reviewed', session('warning'));
    }

    public function test_repeated_requests_for_one_address_are_limited(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'bea@example.test']);

        $this->post(route('password.email'), ['email' => 'bea@example.test']);
        $this->post(route('password.email'), ['email' => 'bea@example.test']);

        // The broker sends at most one link per address per minute.
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_signed_in_users_are_sent_away_from_the_reset_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('password.request'))
            ->assertRedirect(route('dashboard'));
    }
}
