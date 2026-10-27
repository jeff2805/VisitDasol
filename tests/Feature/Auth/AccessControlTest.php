<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nobody may reach another role's area by typing its URL.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const AREAS = ['tourist.dashboard', 'owner.dashboard', 'admin.dashboard'];

    public static function roleAccess(): array
    {
        return [
            'tourist' => ['tourist', 'tourist.dashboard'],
            'resort owner' => ['resortOwner', 'owner.dashboard'],
            'MTO' => ['superAdmin', 'admin.dashboard'],
        ];
    }

    #[DataProvider('roleAccess')]
    public function test_each_role_reaches_only_its_own_area(string $state, string $allowed): void
    {
        $user = User::factory()->{$state}()->create();

        foreach (self::AREAS as $area) {
            $response = $this->actingAs($user)->get(route($area));

            if ($area === $allowed) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    #[DataProvider('roleAccess')]
    public function test_the_dashboard_redirects_by_role(string $state, string $expected): void
    {
        $this->actingAs(User::factory()->{$state}()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route($expected));
    }

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        foreach ([...self::AREAS, 'dashboard', 'profile.edit'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        $this->assertSame('Please sign in to continue.', session('info'));
    }

    public function test_after_signing_in_the_user_returns_to_the_page_they_wanted(): void
    {
        $owner = User::factory()->resortOwner()->create(['password' => 'secret-password-1']);

        $this->get(route('owner.dashboard'))->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'secret-password-1'])
            ->assertRedirect(route('owner.dashboard'));
    }

    public function test_an_account_deactivated_mid_session_is_signed_out_on_the_next_request(): void
    {
        $owner = User::factory()->resortOwner()->create();

        $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk();

        // The MTO deactivates the account while the owner is still signed in.
        $owner->status = UserStatus::Inactive;
        $owner->save();

        $this->get(route('owner.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringContainsString('deactivated', session('warning'));
    }

    public function test_a_tourist_cannot_reach_owner_or_admin_pages_even_with_a_trailing_slash_or_query(): void
    {
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->get('/admin/dashboard?override=1')->assertForbidden();
        $this->actingAs($tourist)->get('/owner/dashboard/')->assertForbidden();
    }

    public function test_the_403_page_is_the_custom_one(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.dashboard'))
            ->assertForbidden()
            ->assertSee('You can’t open this page', false);
    }

    public function test_a_pending_owner_cannot_reach_the_owner_area(): void
    {
        $owner = User::factory()->resortOwner(UserStatus::Pending)->create();

        $this->actingAs($owner)->get(route('owner.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
