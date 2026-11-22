<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Three wrong passwords, then thirty minutes.
 *
 * The part worth testing hardest is not the counting — Laravel's rate
 * limiter does that — but that the OFFICE'S OWN NUMBERS are the ones used.
 * They were not: the settings screen offered two boxes, wrote them to the
 * database, and LoginRequest went on using constants of its own. An office
 * that set an hour got fifteen minutes and nothing said why.
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('known@example.test|127.0.0.1');

        $this->user = User::factory()->create([
            'email' => 'known@example.test',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    private function attempt(string $password = 'wrong-password'): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login'), [
            'email' => 'known@example.test',
            'password' => $password,
        ]);
    }

    public function test_three_wrong_passwords_are_allowed_and_the_fourth_is_not(): void
    {
        for ($try = 1; $try <= 3; $try++) {
            $this->attempt()->assertSessionHasErrors('email');
        }

        $this->attempt();

        $this->assertStringContainsString(
            'Too many sign-in attempts',
            (string) session('errors')?->first('email'),
        );
    }

    /**
     * The door stays shut even for somebody who now knows the password.
     * A lockout that the right password walks through is not a lockout.
     */
    public function test_the_correct_password_does_not_open_a_locked_account(): void
    {
        for ($try = 1; $try <= 4; $try++) {
            $this->attempt();
        }

        $this->attempt('correct-horse-battery');

        $this->assertGuest();
    }

    public function test_the_wait_is_reported_in_minutes_not_seconds(): void
    {
        for ($try = 1; $try <= 4; $try++) {
            $this->attempt();
        }

        $message = (string) session('errors')?->first('email');

        $this->assertStringContainsString('minutes', $message);
        $this->assertStringNotContainsString('1800 seconds', $message);
    }

    public function test_the_door_opens_again_after_the_lockout(): void
    {
        for ($try = 1; $try <= 4; $try++) {
            $this->attempt();
        }

        $this->travel(31)->minutes();

        $this->attempt('correct-horse-battery');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_correct_password_clears_the_count(): void
    {
        $this->attempt();
        $this->attempt();

        $this->attempt('correct-horse-battery');
        $this->post(route('logout'));

        // Two more wrong ones would be the fourth and fifth overall; if
        // the counter had not been cleared, this would be locked out.
        $this->attempt()->assertSessionHasErrors('email');
        $this->attempt()->assertSessionHasErrors('email');

        $this->assertStringNotContainsString(
            'Too many sign-in attempts',
            (string) session('errors')?->first('email'),
        );
    }

    /* The office's numbers are the ones that count --------------------- */

    public function test_the_offices_attempt_limit_is_the_one_enforced(): void
    {
        config(['visitdasol.auth.login_max_attempts' => 5]);

        for ($try = 1; $try <= 5; $try++) {
            $this->attempt();

            $this->assertStringNotContainsString(
                'Too many sign-in attempts',
                (string) session('errors')?->first('email'),
                "Locked out after {$try} tries, but the office allows five.",
            );
        }

        $this->attempt();

        $this->assertStringContainsString(
            'Too many sign-in attempts',
            (string) session('errors')?->first('email'),
        );
    }

    public function test_the_offices_lockout_length_is_the_one_enforced(): void
    {
        config(['visitdasol.auth.lockout_minutes' => 90]);

        for ($try = 1; $try <= 4; $try++) {
            $this->attempt();
        }

        // Half an hour would have been enough under the old constant.
        $this->travel(31)->minutes();
        $this->attempt('correct-horse-battery');

        // Half an hour is not ninety minutes: still shut.
        $this->assertGuest();

        $this->travel(60)->minutes();
        $this->attempt('correct-horse-battery');
        $this->assertAuthenticatedAs($this->user);
    }

    /**
     * One person guessing at one account must not shut everybody out.
     */
    public function test_a_lockout_is_per_account_not_site_wide(): void
    {
        $other = User::factory()->create([
            'email' => 'other@example.test',
            'password' => Hash::make('another-good-password'),
        ]);

        for ($try = 1; $try <= 4; $try++) {
            $this->attempt();
        }

        $this->post(route('login'), [
            'email' => 'other@example.test',
            'password' => 'another-good-password',
        ]);

        $this->assertAuthenticatedAs($other);
    }
}
