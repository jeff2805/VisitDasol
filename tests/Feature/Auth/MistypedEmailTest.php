<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An address at gmail.con cannot be reached, and nothing downstream can
 * tell: the verification code is sent, the queue reports success, the
 * account sits unverified and the visitor waits for mail that bounced
 * off a domain that does not exist. The office then gets a phone call.
 *
 * The sign-up form is the only moment the mistake is still cheap — the
 * person is at the keyboard and remembers what they meant to type. These
 * tests hold that line: both registration forms, and the profile screen,
 * which writes to the same column and had the same hole.
 */
class MistypedEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Domains that do not exist, each one letter from one that does.
     *
     * @return array<string, list<string>>
     */
    public static function mistypedAddresses(): array
    {
        return [
            'wrong tld' => ['bea@gmail.con'],
            'truncated tld' => ['bea@gmail.co'],
            'swapped letters' => ['bea@gmial.com'],
            'dropped letter' => ['bea@gmai.com'],
            'doubled letter' => ['bea@yahooo.com'],
            'yahoo wrong tld' => ['bea@yahoo.con'],
            'hotmail swapped' => ['bea@hotmial.com'],
            'outlook wrong tld' => ['bea@outlook.con'],
        ];
    }

    /**
     * Addresses that must keep working. Some are a single edit from a
     * name on the guarded list and real anyway, which is the whole risk
     * of this kind of check: mail.com and protonmail.ch were both being
     * refused as typos of their better-known neighbours.
     *
     * @return array<string, list<string>>
     */
    public static function realAddresses(): array
    {
        return [
            'gmail' => ['bea@gmail.com'],
            'yahoo philippines' => ['bea@yahoo.com.ph'],
            'icloud' => ['bea@icloud.com'],
            'mail.com' => ['bea@mail.com'],
            'protonmail switzerland' => ['bea@protonmail.ch'],
            'mail.ru short domain' => ['bea@my.com'],
            'a resort of its own' => ['bea@saltbreeze.com.ph'],
            'a local test domain' => ['bea@visitdasol.test'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function touristPayload(string $email): array
    {
        return [
            'first_name' => 'Bea',
            'last_name' => 'Santos',
            'email' => $email,
            'phone' => '09171234567',
            'home_city' => 'Manila',
            'home_country' => 'Philippines',
            'password' => 'Holiday-2030',
            'password_confirmation' => 'Holiday-2030',
            'terms' => '1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerPayload(string $email): array
    {
        return [
            'first_name' => 'Carmela',
            'last_name' => 'Reyes',
            'email' => $email,
            'phone' => '09171234567',
            'password' => 'Seaside-2030',
            'password_confirmation' => 'Seaside-2030',
            'business_name' => 'Salt Breeze Resort',
            'position' => 'Owner',
            'business_permit_no' => 'BP-2030-1234',
            'terms' => '1',
        ];
    }

    /* Tourist sign-up -------------------------------------------------- */

    #[DataProvider('mistypedAddresses')]
    public function test_a_mistyped_address_never_reaches_the_users_table(string $email): void
    {
        $this->post(route('register'), $this->touristPayload($email))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertGuest();
    }

    #[DataProvider('realAddresses')]
    public function test_a_real_address_still_registers(string $email): void
    {
        $this->post(route('register'), $this->touristPayload($email))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    /* Owner sign-up ---------------------------------------------------- */

    #[DataProvider('mistypedAddresses')]
    public function test_the_owner_form_refuses_it_too(string $email): void
    {
        $this->post(route('register.owner'), $this->ownerPayload($email))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    /* Profile edit ----------------------------------------------------- */

    /**
     * The hole the registration fix on its own would have left: an
     * account already created can rewrite its own address, into the same
     * column, through a different form.
     */
    public function test_an_existing_account_cannot_mistype_its_way_out(): void
    {
        $user = User::factory()->tourist()->create([
            'email' => 'bea@gmail.com',
            'password' => Hash::make('Holiday-2030'),
        ]);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => 'bea@gmail.con',
                'phone' => '09171234567',
                'current_password' => 'Holiday-2030',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame('bea@gmail.com', $user->fresh()->email);
    }

    /**
     * Keeping your own address is not changing it to a typo.
     */
    public function test_saving_a_profile_without_touching_the_email_is_fine(): void
    {
        $user = User::factory()->tourist()->create(['email' => 'bea@gmail.com']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => 'Beatriz',
                'last_name' => $user->last_name,
                'email' => 'bea@gmail.com',
                'phone' => '09171234567',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Beatriz', $user->fresh()->first_name);
    }

    /* The message ------------------------------------------------------ */

    /**
     * "Invalid email" against an address that looks right to the person
     * who typed it gets retyped unchanged. Naming the domain they meant
     * is what makes the error actionable.
     */
    public function test_the_error_names_the_domain_they_probably_meant(): void
    {
        $this->post(route('register'), $this->touristPayload('bea@gmail.con'));

        $message = (string) session('errors')?->first('email');

        $this->assertStringContainsString('gmail.con', $message);
        $this->assertStringContainsString('gmail.com', $message);
    }

    /**
     * A domain nowhere near a known provider is somebody's own mail
     * server, not a mistake, and the rule must keep its hands off it.
     */
    public function test_an_unfamiliar_domain_is_not_second_guessed(): void
    {
        $this->post(route('register'), $this->touristPayload('bea@tourism.gov.ph'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'bea@tourism.gov.ph']);
    }
}
