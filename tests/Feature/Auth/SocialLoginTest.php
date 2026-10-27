<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Signing in with Google or Facebook.
 *
 * The whole risk in this feature is one question: when may a provider be
 * trusted to say who somebody is? Most of what follows is about the answer
 * being "not always".
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'pretend-id',
            'services.google.client_secret' => 'pretend-secret',
            'services.facebook.client_id' => 'pretend-id',
            'services.facebook.client_secret' => 'pretend-secret',
        ]);
    }

    /** Stands in for the answer the provider sends back. */
    private function arrives(string $provider, array $attributes = []): void
    {
        $account = Mockery::mock(SocialUser::class);
        $account->shouldReceive('getId')->andReturn($attributes['id'] ?? 'provider-123');
        $account->shouldReceive('getEmail')->andReturn($attributes['email'] ?? 'bea@example.test');
        $account->shouldReceive('getName')->andReturn($attributes['name'] ?? 'Bea Santos');
        $account->shouldReceive('getRaw')->andReturn(
            array_key_exists('verified', $attributes) && $attributes['verified']
                ? ['email_verified' => true]
                : []
        );

        Socialite::shouldReceive('driver')->with($provider)->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($account);
    }

    /* The buttons ---------------------------------------------------------- */

    public function test_the_buttons_appear_once_the_keys_are_set(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertSee('Continue with Facebook');

        $this->get(route('register'))->assertOk()->assertSee('Sign up with Google');
    }

    /**
     * With no keys there is nothing to press and nothing behind it. A button
     * that leads to an error page is worse than no button.
     */
    public function test_nothing_is_offered_or_reachable_without_keys(): void
    {
        config(['services.google.client_id' => null, 'services.facebook.client_id' => null]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Continue with Google')
            ->assertDontSee('Continue with Facebook');

        $this->get(route('social.redirect', 'google'))->assertNotFound();
        $this->get(route('social.callback', 'google'))->assertNotFound();
    }

    public function test_a_provider_nobody_has_heard_of_is_not_answered(): void
    {
        $this->get(route('social.redirect', 'myspace'))->assertNotFound();
    }

    /** The owner application is not something a button can hand out. */
    public function test_the_resort_owner_application_offers_no_shortcut(): void
    {
        $this->get(route('register.owner'))
            ->assertOk()
            ->assertDontSee('Continue with Google')
            ->assertDontSee('Sign up with Google');
    }

    /* Signing in ----------------------------------------------------------- */

    public function test_a_new_visitor_gets_a_tourist_account(): void
    {
        $this->arrives('google', ['verified' => true]);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $user = User::sole();

        $this->assertSame('bea@example.test', $user->email);
        $this->assertSame('Bea', $user->first_name);
        $this->assertSame('Santos', $user->last_name);
        $this->assertSame(UserRole::Tourist, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        // Google checked the address, so there is nothing left to prove.
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Facebook says nothing about whether it checked the address, so a new
     * account made that way still goes through the emailed code.
     */
    public function test_an_unchecked_address_still_has_to_be_confirmed(): void
    {
        $this->arrives('facebook');

        $this->get(route('social.callback', 'facebook'))->assertRedirect(route('dashboard'));

        $this->assertNull(User::sole()->email_verified_at);
    }

    public function test_coming_back_signs_into_the_same_account(): void
    {
        $this->arrives('google', ['verified' => true]);
        $this->get(route('social.callback', 'google'));

        $this->post(route('logout'));

        $this->arrives('google', ['verified' => true]);
        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count(), 'a second visit made a second account');
        $this->assertSame(1, SocialAccount::count());
        $this->assertAuthenticatedAs(User::sole());
    }

    /**
     * Matched on the provider's own id, never the email: an address can change
     * hands at the provider, and the account must not follow it.
     */
    public function test_the_account_follows_the_provider_id_not_the_email(): void
    {
        $this->arrives('google', ['verified' => true]);
        $this->get(route('social.callback', 'google'));
        $this->post(route('logout'));

        // Same person at Google, new address there.
        $this->arrives('google', ['verified' => true, 'email' => 'bea.new@example.test']);
        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count());
        // The Visit Dasol address is theirs to change, not the provider's.
        $this->assertSame('bea@example.test', User::sole()->email);
    }

    /* The takeover, refused ------------------------------------------------- */

    /**
     * THE one that matters.
     *
     * Anyone can make a Facebook account claiming any email address. If that
     * were enough to walk into an existing Visit Dasol account, every
     * reservation on the site would be one Facebook sign-up away from a
     * stranger.
     */
    public function test_an_unverified_address_cannot_claim_an_existing_account(): void
    {
        $victim = User::factory()->create(['email' => 'bea@example.test']);

        $this->arrives('facebook', ['email' => 'bea@example.test']);

        $this->get(route('social.callback', 'facebook'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(1, User::count());
        $this->assertSame($victim->id, User::sole()->id);
    }

    /** Verified, so the two really are the same person. */
    public function test_a_verified_address_is_linked_to_the_account_that_has_it(): void
    {
        $existing = User::factory()->create(['email' => 'bea@example.test']);

        $this->arrives('google', ['verified' => true, 'email' => 'bea@example.test']);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => 'google',
        ]);
    }

    /**
     * A deactivated account must not become usable just because it arrived
     * through a different door.
     */
    public function test_an_account_the_office_switched_off_stays_off(): void
    {
        User::factory()->inactive()->create(['email' => 'bea@example.test']);

        $this->arrives('google', ['verified' => true, 'email' => 'bea@example.test']);

        $this->get(route('social.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    /* When the provider is unhelpful ---------------------------------------- */

    public function test_a_provider_that_shares_no_address_is_refused_kindly(): void
    {
        $this->arrives('facebook', ['email' => '']);

        $this->get(route('social.callback', 'facebook'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /** They pressed cancel on Google's screen. Not an error. */
    public function test_backing_out_at_the_provider_is_not_an_error(): void
    {
        $this->get(route('social.callback', ['provider' => 'google', 'error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('info');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
