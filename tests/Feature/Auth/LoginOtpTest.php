<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\SignInCode;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The one-time code asked for on every sign-in.
 *
 * The property every test here is really about: THE PASSWORD ALONE OPENS
 * NOTHING. Between the two steps the session holds an id and a timestamp,
 * and a request to any page behind auth is still a request from a stranger.
 *
 * Switched off for the rest of the suite (see AUTH_LOGIN_OTP in phpunit.xml),
 * because a test about a booking has no business reading a mailbox. These
 * tests switch it back on.
 */
class LoginOtpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['visitdasol.otp.require_on_login' => true]);

        $this->user = User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function signIn(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login'), array_merge([
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery',
        ], $extra));
    }

    /** The code that was actually emailed, read off the notification. */
    private function codeSentTo(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, SignInCode::class, function (SignInCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertNotNull($code, 'No sign-in code was emailed.');

        return (string) $code;
    }

    /* The two steps ------------------------------------------------------ */

    public function test_the_right_password_does_not_sign_anybody_in(): void
    {
        Notification::fake();

        $this->signIn()->assertRedirect(route('login.otp'));

        // A correct password must not be enough on its own.
        $this->assertGuest();
    }

    public function test_the_right_password_emails_a_code(): void
    {
        Notification::fake();

        $this->signIn();

        Notification::assertSentTo($this->user, SignInCode::class);

        $this->assertDatabaseHas('email_verification_codes', [
            'user_id' => $this->user->id,
            'purpose' => OtpPurpose::Login->value,
        ]);
    }

    public function test_the_code_completes_the_sign_in(): void
    {
        Notification::fake();

        $this->signIn();

        $this->post(route('login.otp.verify'), ['code' => $this->codeSentTo($this->user)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);

        // The code is spent, not left lying about for a second use.
        $this->assertDatabaseMissing('email_verification_codes', [
            'user_id' => $this->user->id,
            'purpose' => OtpPurpose::Login->value,
        ]);
    }

    public function test_the_wrong_password_never_reaches_the_code_step(): void
    {
        Notification::fake();

        $this->signIn(['password' => 'not-the-password'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    /* What the half-finished state may and may not do -------------------- */

    public function test_a_waiting_sign_in_reaches_no_page_behind_auth(): void
    {
        Notification::fake();

        $this->signIn();

        // Half way through, and still nobody.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_code_screen_is_unreachable_without_a_password(): void
    {
        $this->get(route('login.otp'))->assertRedirect(route('login'));

        $this->post(route('login.otp.verify'), ['code' => '123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_wrong_code_signs_nobody_in(): void
    {
        Notification::fake();

        $this->signIn();
        $real = $this->codeSentTo($this->user);

        $wrong = $real === '000000' ? '111111' : '000000';

        $this->post(route('login.otp.verify'), ['code' => $wrong])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_code_dies_after_too_many_wrong_guesses(): void
    {
        Notification::fake();

        $this->signIn();
        $real = $this->codeSentTo($this->user);
        $wrong = $real === '000000' ? '111111' : '000000';

        $max = (int) config('visitdasol.otp.max_attempts');

        for ($try = 0; $try < $max; $try++) {
            $this->post(route('login.otp.verify'), ['code' => $wrong]);
        }

        $this->assertDatabaseMissing('email_verification_codes', [
            'user_id' => $this->user->id,
            'purpose' => OtpPurpose::Login->value,
        ]);

        // Even the right code is no use now: it has been thrown away.
        $this->post(route('login.otp.verify'), ['code' => $real]);
        $this->assertGuest();
    }

    /**
     * A browser left on the code screen is not a way in an hour later.
     */
    public function test_the_waiting_sign_in_expires_and_asks_for_the_password_again(): void
    {
        Notification::fake();

        $this->signIn();
        $code = $this->codeSentTo($this->user);

        $this->travel((int) config('visitdasol.otp.login_window_minutes') + 1)->minutes();

        $this->post(route('login.otp.verify'), ['code' => $code])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_starting_again_throws_the_waiting_sign_in_away(): void
    {
        Notification::fake();

        $this->signIn();

        $this->post(route('login.otp.cancel'))->assertRedirect(route('login'));

        $this->get(route('login.otp'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The office may suspend an account between the password and the code,
     * and the code must not be a way past that.
     */
    public function test_an_account_suspended_mid_sign_in_is_refused(): void
    {
        Notification::fake();

        $this->signIn();
        $code = $this->codeSentTo($this->user);

        $this->user->forceFill(['status' => \App\Enums\UserStatus::Inactive])->save();

        $this->post(route('login.otp.verify'), ['code' => $code])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /* The two kinds of code do not stand in for each other --------------- */

    public function test_a_sign_in_code_does_not_confirm_an_email_address(): void
    {
        Notification::fake();

        $unverified = User::factory()->unverified()->create([
            'email' => 'new@example.test',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->post(route('login'), [
            'email' => 'new@example.test',
            'password' => 'correct-horse-battery',
        ]);

        $this->post(route('login.otp.verify'), ['code' => $this->codeSentTo($unverified)]);

        $this->assertNull(
            $unverified->fresh()->email_verified_at,
            'Signing in must not quietly confirm an address nobody confirmed.'
        );
    }

    public function test_a_registration_code_cannot_be_used_to_sign_in(): void
    {
        Notification::fake();

        $service = app(EmailVerificationService::class);

        // A registration code is outstanding...
        $registration = $service->send($this->user, OtpPurpose::Registration);

        // ...and a sign-in is started, which issues its own.
        $this->signIn();

        $this->post(route('login.otp.verify'), ['code' => $registration])
            ->assertSessionHasErrors('code');

        // A code issued for one job must not do the other.
        $this->assertGuest();
    }

    /**
     * The two codes live side by side rather than destroying each other.
     */
    public function test_both_kinds_of_code_can_be_outstanding_at_once(): void
    {
        Notification::fake();

        app(EmailVerificationService::class)->send($this->user, OtpPurpose::Registration);
        $this->signIn();

        $this->assertSame(
            2,
            EmailVerificationCode::query()->where('user_id', $this->user->id)->count(),
            'Asking for one code must not destroy the other.'
        );
    }

    /* The switch --------------------------------------------------------- */

    public function test_the_site_can_be_put_back_on_passwords_alone(): void
    {
        config(['visitdasol.otp.require_on_login' => false]);
        Notification::fake();

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        Notification::assertNothingSent();
    }
}
