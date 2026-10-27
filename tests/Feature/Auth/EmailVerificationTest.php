<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailWithCode;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Confirming an email address with a one-time code.
 *
 * Accounts that already carry an email_verified_at (every account made before
 * this step existed, and every factory user) are never asked again, which is
 * why the rest of the suite is unaffected.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedTourist(): User
    {
        return User::factory()->unverified()->create();
    }

    /** Sends a real code and returns the digits that were emailed. */
    private function sendCode(User $user): string
    {
        return app(EmailVerificationService::class)->send($user);
    }

    /* The gate ---------------------------------------------------------- */

    public function test_an_unconfirmed_account_cannot_reach_the_dashboard(): void
    {
        $this->actingAs($this->unverifiedTourist());

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->get(route('tourist.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_a_confirmed_account_passes_straight_through(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('tourist.dashboard'));
        $this->get(route('tourist.dashboard'))->assertOk();
    }

    public function test_owners_and_admins_are_held_too(): void
    {
        $this->actingAs(User::factory()->resortOwner()->unverified()->create());
        $this->get(route('owner.dashboard'))->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->superAdmin()->unverified()->create());
        $this->get(route('admin.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_a_confirmed_account_is_not_asked_again(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    }

    /* The screen -------------------------------------------------------- */

    public function test_opening_the_screen_sends_a_code(): void
    {
        Notification::fake();
        $user = $this->unverifiedTourist();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Check your email')
            ->assertSee($user->email);

        Notification::assertSentTo($user, VerifyEmailWithCode::class);
        $this->assertDatabaseCount('email_verification_codes', 1);
    }

    public function test_reopening_the_screen_does_not_send_a_second_code(): void
    {
        Notification::fake();
        $user = $this->unverifiedTourist();

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->actingAs($user)->get(route('verification.notice'))->assertOk();

        Notification::assertSentToTimes($user, VerifyEmailWithCode::class, 1);
    }

    public function test_the_screen_gives_the_countdowns_something_to_count(): void
    {
        Notification::fake();
        $user = $this->unverifiedTourist();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertViewHas('secondsUntilResend', fn (int $left): bool => $left > 0)
            ->assertViewHas('secondsUntilExpiry', fn (int $left): bool => $left > 0)
            ->assertViewHas('attemptsLeft', (int) config('visitdasol.otp.max_attempts'))
            // The button the countdown releases must be the one it names.
            ->assertSee('data-countdown-enables="resend-code"', false)
            ->assertSee('id="resend-code"', false);

        $this->travel((int) config('visitdasol.otp.resend_cooldown_seconds') + 1)->seconds();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertViewHas('secondsUntilResend', 0);
    }

    /* Entering the code -------------------------------------------------- */

    public function test_the_right_code_confirms_the_address(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);

        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('email_verification_codes', 0);

        $this->get(route('tourist.dashboard'))->assertOk();
    }

    public function test_spaces_and_dashes_in_the_code_are_ignored(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);
        $spaced = substr($code, 0, 3).' - '.substr($code, 3);

        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $spaced])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);

        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $this->wrongCode($code)])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(1, EmailVerificationCode::sole()->attempts);
    }

    public function test_a_wrong_code_says_how_many_tries_are_left(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);
        $wrong = $this->wrongCode($code);
        $limit = (int) config('visitdasol.otp.max_attempts');

        $this->actingAs($user)->post(route('verification.verify'), ['code' => $wrong])
            ->assertSessionHasErrors(['code' => 'That code is not correct. Please check the email and try again. '
                .'You have '.($limit - 1).' tries left before you need a new code.']);

        // Spend guesses until one remains, whatever the configured limit is.
        while (EmailVerificationCode::sole()->attemptsLeft() > 2) {
            $this->actingAs($user)->post(route('verification.verify'), ['code' => $wrong]);
        }

        // On the last chance the wording stops counting and warns instead.
        $this->actingAs($user)->post(route('verification.verify'), ['code' => $wrong])
            ->assertSessionHasErrors(['code' => 'That code is not correct. Please check the email and try again. '
                .'One more wrong code and you will need a new one.']);
    }

    public function test_the_code_dies_after_too_many_wrong_guesses(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);
        $wrong = $this->wrongCode($code);
        $limit = (int) config('visitdasol.otp.max_attempts');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($user)->post(route('verification.verify'), ['code' => $wrong]);
        }

        $this->assertDatabaseCount('email_verification_codes', 0);

        // Even the code that was correct a moment ago no longer works.
        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);

        $this->travel((int) config('visitdasol.otp.ttl_minutes') + 1)->minutes();

        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('email_verification_codes', 0);
    }

    public function test_one_persons_code_does_not_work_for_another(): void
    {
        $bea = $this->unverifiedTourist();
        $kevin = $this->unverifiedTourist();

        $beasCode = $this->sendCode($bea);
        $this->sendCode($kevin);

        $this->actingAs($kevin)
            ->post(route('verification.verify'), ['code' => $beasCode])
            ->assertSessionHasErrors('code');

        $this->assertNull($kevin->fresh()->email_verified_at);
    }

    public function test_the_code_is_validated_before_it_is_checked(): void
    {
        $user = $this->unverifiedTourist();
        $this->sendCode($user);

        $this->actingAs($user)->post(route('verification.verify'), ['code' => ''])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('verification.verify'), ['code' => '12345'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('verification.verify'), ['code' => 'abcdef'])->assertSessionHasErrors('code');

        // None of those counted as a guess.
        $this->assertSame(0, EmailVerificationCode::sole()->attempts);
    }

    /* Sending another code ----------------------------------------------- */

    public function test_a_new_code_replaces_the_old_one(): void
    {
        $user = $this->unverifiedTourist();
        $first = $this->sendCode($user);

        $this->travel((int) config('visitdasol.otp.resend_cooldown_seconds') + 1)->seconds();

        $this->actingAs($user)->post(route('verification.resend'))->assertSessionHas('success');
        $this->assertDatabaseCount('email_verification_codes', 1);

        $this->actingAs($user)
            ->post(route('verification.verify'), ['code' => $first])
            ->assertSessionHasErrors('code');
    }

    public function test_codes_cannot_be_requested_back_to_back(): void
    {
        Notification::fake();
        $user = $this->unverifiedTourist();

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->actingAs($user)->post(route('verification.resend'))->assertSessionHas('warning');

        Notification::assertSentToTimes($user, VerifyEmailWithCode::class, 1);
    }

    /* Storage ------------------------------------------------------------ */

    public function test_the_code_is_stored_only_as_a_hash(): void
    {
        $user = $this->unverifiedTourist();
        $code = $this->sendCode($user);

        $stored = EmailVerificationCode::sole();

        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString($code, $stored->code_hash);
        $this->assertTrue(Hash::check($code, $stored->code_hash));
    }

    public function test_codes_are_thrown_away_with_the_account(): void
    {
        $user = $this->unverifiedTourist();
        $this->sendCode($user);

        $user->delete();

        $this->assertDatabaseCount('email_verification_codes', 0);
    }

    /* Ways out ----------------------------------------------------------- */

    public function test_an_unconfirmed_account_can_still_fix_its_address(): void
    {
        $user = $this->unverifiedTourist();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_an_unconfirmed_account_can_sign_out(): void
    {
        $this->actingAs($this->unverifiedTourist())
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_changing_the_email_address_asks_for_a_new_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'moved@example.test',
            'phone' => $user->phone,
            'home_city' => $user->home_city,
            'home_country' => $user->home_country,
            // Changing an address needs the current password (UpdateProfileRequest).
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    /* The whole path ----------------------------------------------------- */

    public function test_registration_sends_a_code_and_the_dashboard_waits_for_it(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'first_name' => 'Bea',
            'last_name' => 'Santos',
            'email' => 'bea@example.test',
            'phone' => '09171234567',
            'home_city' => 'Manila',
            'home_country' => 'Philippines',
            'password' => 'holiday-2030',
            'password_confirmation' => 'holiday-2030',
            'terms' => '1',
        ])->assertRedirect(route('verification.notice'));

        $tourist = User::sole();
        $this->assertNull($tourist->email_verified_at);

        $code = null;
        Notification::assertSentTo($tourist, VerifyEmailWithCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->assertMatchesRegularExpression(
            '/^\d{'.config('visitdasol.otp.length').'}$/',
            (string) $code,
            'The emailed code should be exactly the configured number of digits.'
        );

        $this->get(route('tourist.dashboard'))->assertRedirect(route('verification.notice'));

        $this->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->get(route('tourist.dashboard'))->assertOk();
    }

    public function test_an_owner_confirms_on_the_first_sign_in_after_approval(): void
    {
        $owner = User::factory()
            ->resortOwner(UserStatus::Active)
            ->unverified()
            ->create(['password' => Hash::make('seaside-2030')]);

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'seaside-2030'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $code = $this->sendCode($owner->fresh());

        $this->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        $this->get(route('owner.dashboard'))->assertOk();
    }

    /** A code of the right shape that is certainly not the real one. */
    private function wrongCode(string $real): string
    {
        $wrong = str_repeat('0', strlen($real));

        return $wrong === $real ? str_repeat('1', strlen($real)) : $wrong;
    }
}
