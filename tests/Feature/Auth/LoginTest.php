<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    public function test_an_active_tourist_can_sign_in(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $response = $this->post(route('login'), [
            'email' => $tourist->email,
            'password' => 'secret-password-1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($tourist);
        $this->assertNotNull($tourist->fresh()->last_login_at);

        $this->get(route('dashboard'))->assertRedirect(route('tourist.dashboard'));
    }

    public function test_the_email_is_not_case_sensitive(): void
    {
        $tourist = User::factory()->create(['email' => 'traveller@example.test', 'password' => 'secret-password-1']);

        $this->post(route('login'), ['email' => '  TRAVELLER@example.test ', 'password' => 'secret-password-1'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($tourist);
    }

    public function test_signing_in_starts_a_new_session(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => $tourist->email, 'password' => 'secret-password-1']);

        $this->assertNotSame($before, session()->getId(), 'The session id must change after signing in.');
    }

    public function test_a_wrong_password_and_an_unknown_email_give_the_same_message(): void
    {
        User::factory()->create(['email' => 'known@example.test', 'password' => 'secret-password-1']);

        $wrongPassword = $this->post(route('login'), ['email' => 'known@example.test', 'password' => 'wrong-password']);
        $unknownEmail = $this->post(route('login'), ['email' => 'nobody@example.test', 'password' => 'wrong-password']);

        $wrongPassword->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
        $unknownEmail->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
        $this->assertGuest();
    }

    public function test_the_password_is_never_flashed_back(): void
    {
        User::factory()->create(['email' => 'known@example.test', 'password' => 'secret-password-1']);

        $this->post(route('login'), ['email' => 'known@example.test', 'password' => 'wrong-password'])
            ->assertSessionMissing('_old_input.password');
    }

    public static function refusedAccounts(): array
    {
        return [
            'pending owner' => [UserStatus::Pending, 'still being reviewed'],
            'rejected owner' => [UserStatus::Rejected, 'was not approved'],
            'inactive owner' => [UserStatus::Inactive, 'has been deactivated'],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_only_active_accounts_may_sign_in(UserStatus $status, string $expected): void
    {
        $owner = User::factory()->resortOwner($status)->create(['password' => 'secret-password-1']);

        $response = $this->post(route('login'), ['email' => $owner->email, 'password' => 'secret-password-1']);

        $response->assertRedirect();
        $this->assertGuest();

        $message = session('warning') ?? session('error');
        $this->assertStringContainsString($expected, $message);
        $this->assertNull($owner->fresh()->last_login_at);
    }

    public function test_a_rejected_owner_sees_the_reason(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Rejected)->create(['password' => 'secret-password-1']);
        $owner->ownerProfile->forceFill(['review_remarks' => 'The permit number could not be verified.'])->save();

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'secret-password-1']);

        $this->assertStringContainsString('The permit number could not be verified.', session('error'));
    }

    public function test_an_inactive_tourist_cannot_sign_in(): void
    {
        $tourist = User::factory()->inactive()->create(['password' => 'secret-password-1']);

        $this->post(route('login'), ['email' => $tourist->email, 'password' => 'secret-password-1']);

        $this->assertGuest();
    }

    public function test_sign_in_is_rate_limited_after_five_failures(): void
    {
        $tourist = User::factory()->create(['email' => 'target@example.test', 'password' => 'secret-password-1']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'wrong-password']);
        }

        // Even the correct password is refused while the limit applies.
        $response = $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'secret-password-1']);

        $response->assertSessionHasErrorsIn('default', ['email']);
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertNull($tourist->fresh()->last_login_at);
    }

    public function test_a_successful_sign_in_clears_the_attempt_counter(): void
    {
        $tourist = User::factory()->create(['email' => 'target@example.test', 'password' => 'secret-password-1']);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'secret-password-1']);
        $this->assertAuthenticatedAs($tourist);

        Auth::logout();

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => 'target@example.test', 'password' => 'secret-password-1'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_remember_me_sets_a_cookie(): void
    {
        $tourist = User::factory()->create(['password' => 'secret-password-1']);

        $response = $this->post(route('login'), [
            'email' => $tourist->email,
            'password' => 'secret-password-1',
            'remember' => '1',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($tourist->fresh()->remember_token);
    }

    public function test_signed_in_users_are_sent_away_from_the_sign_in_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_signing_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_signing_out_requires_a_post_request(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/logout')
            ->assertStatus(405);
    }

    public function test_a_deactivated_owner_can_still_sign_out(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Inactive)->create();

        $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
    }
}
