<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Eight characters, upper and lower case, a number and a symbol — on
 * every form that sets a password, not just the one that was checked.
 *
 * The part worth testing hardest is not the strength check itself, which
 * is Laravel's. It is that the OFFICE'S OWN ANSWERS are the ones applied.
 * They were not: the settings screen offered four boxes — length, mixed
 * case, a number, a symbol — stored every answer, and AppServiceProvider
 * went on requiring letters and numbers only. The screen's default said
 * upper and lower case was required when it was not, so an office
 * reading it would have described a policy this site did not have.
 */
class PasswordStrengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Passwords long enough but not strong enough.
     *
     * @return array<string, list<string>>
     */
    public static function weakPasswords(): array
    {
        return [
            'all lower case' => ['holiday-2030'],
            'all upper case' => ['HOLIDAY-2030'],
            'no number' => ['Holiday-Dasol'],
            'no symbol' => ['Holiday2030'],
            'no letter' => ['1234-5678'],
            'too short' => ['Hol-123'],
            'the old policy would have passed this' => ['holiday2030'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function touristPayload(string $password): array
    {
        return [
            'first_name' => 'Bea',
            'last_name' => 'Santos',
            'email' => 'bea@gmail.com',
            'phone' => '09171234567',
            'home_city' => 'Manila',
            'home_country' => 'Philippines',
            'password' => $password,
            'password_confirmation' => $password,
            'terms' => '1',
        ];
    }

    /* Registration ----------------------------------------------------- */

    #[DataProvider('weakPasswords')]
    public function test_a_weak_password_cannot_create_a_tourist_account(string $password): void
    {
        $this->post(route('register'), $this->touristPayload($password))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'bea@gmail.com']);
    }

    public function test_a_strong_password_registers(): void
    {
        $this->post(route('register'), $this->touristPayload('Holiday-2030'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('Holiday-2030', User::where('email', 'bea@gmail.com')->sole()->password)
        );
    }

    #[DataProvider('weakPasswords')]
    public function test_the_owner_form_refuses_a_weak_password_too(string $password): void
    {
        $this->post(route('register.owner'), [
            'first_name' => 'Carmela',
            'last_name' => 'Reyes',
            'email' => 'carmela@gmail.com',
            'phone' => '09171234567',
            'password' => $password,
            'password_confirmation' => $password,
            'business_name' => 'Salt Breeze Resort',
            'position' => 'Owner',
            'terms' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'carmela@gmail.com']);
    }

    /* Changing an existing password ------------------------------------ */

    /**
     * The hole fixing registration alone would have left. An account can
     * set its own password on two other forms, and a policy that only
     * covers the first one is a policy in name.
     */
    #[DataProvider('weakPasswords')]
    public function test_the_profile_screen_refuses_a_weak_password(string $password): void
    {
        // Plain text here: the model's "hashed" cast does the hashing, so
        // passing Hash::make() would hash it twice and the current-password
        // check would fail for a reason that has nothing to do with strength.
        $user = User::factory()->tourist()->create(['password' => 'Current-2030']);

        $this->actingAs($user)
            ->put(route('profile.password'), [
                'current_password' => 'Current-2030',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors('password', null, 'password');

        $this->assertTrue(Hash::check('Current-2030', $user->fresh()->password));
    }

    /* The office's answers are the ones that count --------------------- */

    /**
     * Turning the symbol requirement off must actually turn it off. If it
     * does not, the box is decoration.
     */
    public function test_a_requirement_the_office_switched_off_is_not_enforced(): void
    {
        config(['visitdasol.auth.password.symbols' => false]);

        $this->post(route('register'), $this->touristPayload('Holiday2030'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'bea@gmail.com']);
    }

    /**
     * And switching one on must enforce it. Mixed case was the setting
     * that read true while nothing applied it.
     */
    public function test_a_requirement_the_office_switched_on_is_enforced(): void
    {
        config(['visitdasol.auth.password.mixed_case' => true]);

        $this->post(route('register'), $this->touristPayload('holiday-2030'))
            ->assertSessionHasErrors('password');
    }

    public function test_the_offices_minimum_length_is_the_one_enforced(): void
    {
        config(['visitdasol.auth.password.min' => 16]);

        // Strong in every other way, and twelve characters long.
        $this->post(route('register'), $this->touristPayload('Holiday-2030'))
            ->assertSessionHasErrors('password');

        $this->post(route('register'), $this->touristPayload('Holiday-2030-Dasol'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Eight stands whatever is in the settings row. The screen refuses a
     * smaller number, but a value edited straight into the database, or
     * stored before that check existed, must not weaken the site.
     */
    public function test_eight_characters_is_a_floor_the_settings_cannot_lower(): void
    {
        config(['visitdasol.auth.password.min' => 4]);

        $this->assertSame(8, PasswordPolicy::minimumLength());

        $this->post(route('register'), $this->touristPayload('Ab1-cd'))
            ->assertSessionHasErrors('password');
    }

    /**
     * The whole claim, end to end: a row the office saved in the settings
     * table changes what the registration form accepts.
     *
     * The tests above set config() directly, which proves the policy
     * reads configuration but not that the office's screen writes
     * anywhere the policy looks. That gap is exactly where the bug lived
     * — four boxes, four stored rows, and a hardcoded rule that never
     * consulted them.
     */
    public function test_a_setting_saved_by_the_office_changes_what_the_form_accepts(): void
    {
        Setting::updateOrCreate(
            ['key' => 'security.password_symbols'],
            ['value' => '0'],
        );

        $settings = app(SettingsService::class);
        $settings->flush();
        $settings->applyToConfig();

        $this->assertFalse(config('visitdasol.auth.password.symbols'));

        // No symbol, and accepted — because the office said so, not
        // because a template or a constant said so.
        $this->post(route('register'), $this->touristPayload('Holiday2030'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'bea@gmail.com']);
    }

    /* The hint matches the rule ---------------------------------------- */

    /**
     * A hint that disagrees with the rule is worse than no hint: the
     * visitor does what it says, the form refuses them anyway, and
     * nothing on the page says which of the two to believe. The four
     * templates each spelled the sentence out by hand, which is how they
     * came to describe a policy the site had stopped enforcing.
     */
    public function test_the_sign_up_page_describes_the_policy_it_enforces(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee(PasswordPolicy::describe(), escape: false);
    }

    public function test_the_hint_follows_the_office_rather_than_the_template(): void
    {
        config([
            'visitdasol.auth.password.symbols' => false,
            'visitdasol.auth.password.min' => 12,
        ]);

        $description = PasswordPolicy::describe();

        $this->assertStringContainsString('12 characters', $description);
        $this->assertStringNotContainsString('symbol', $description);

        $this->get(route('register'))->assertSee($description, escape: false);
    }

    /**
     * Every form that sets a password shows the same sentence, because
     * every one of them applies the same rule.
     */
    public function test_each_password_form_shows_the_same_policy(): void
    {
        $user = User::factory()->tourist()->create();
        $description = PasswordPolicy::describe();

        $this->get(route('register'))->assertSee($description, escape: false);
        $this->get(route('register.owner'))->assertSee($description, escape: false);
        $this->actingAs($user)->get(route('profile.edit'))->assertSee($description, escape: false);
    }
}
