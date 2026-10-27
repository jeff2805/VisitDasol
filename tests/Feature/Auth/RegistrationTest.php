<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\OwnerProfile;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function touristPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Bea',
            'last_name' => 'Santos',
            'email' => 'bea@example.test',
            'phone' => '0917 123 4567',
            'home_city' => 'Manila',
            'home_country' => 'Philippines',
            'password' => 'holiday-2030',
            'password_confirmation' => 'holiday-2030',
            'terms' => '1',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Carmela',
            'last_name' => 'Reyes',
            'email' => 'carmela@example.test',
            'phone' => '09171234567',
            'password' => 'seaside-2030',
            'password_confirmation' => 'seaside-2030',
            'business_name' => 'Salt Breeze Resort',
            'position' => 'Owner',
            'business_permit_no' => 'BP-2030-1234',
            'terms' => '1',
        ], $overrides);
    }

    /* Tourist ---------------------------------------------------------- */

    public function test_the_tourist_form_renders(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('Create your account');
    }

    public function test_a_tourist_account_is_active_and_signed_in_immediately(): void
    {
        $response = $this->post(route('register'), $this->touristPayload());

        // Signed in at once, but held at the code screen until the email
        // address is confirmed.
        $response->assertRedirect(route('verification.notice'));

        $tourist = User::sole();
        $this->assertSame(UserRole::Tourist, $tourist->role);
        $this->assertSame(UserStatus::Active, $tourist->status);
        $this->assertSame('09171234567', $tourist->phone, 'Spaces should be stripped from phone numbers.');
        $this->assertTrue(Hash::check('holiday-2030', $tourist->password));
        $this->assertAuthenticatedAs($tourist);
        $this->assertNull($tourist->email_verified_at);
    }

    public function test_a_tourist_cannot_choose_their_own_role_or_status(): void
    {
        $this->post(route('register'), $this->touristPayload([
            'role' => 'super_admin',
            'status' => 'active',
        ]));

        $this->assertSame(UserRole::Tourist, User::sole()->role);
    }

    public function test_tourist_validation(): void
    {
        $this->post(route('register'), $this->touristPayload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->post(route('register'), $this->touristPayload(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post(route('register'), $this->touristPayload(['password' => 'short1', 'password_confirmation' => 'short1']))->assertSessionHasErrors('password');
        $this->post(route('register'), $this->touristPayload(['password' => 'alllettersonly', 'password_confirmation' => 'alllettersonly']))->assertSessionHasErrors('password');
        $this->post(route('register'), $this->touristPayload(['terms' => null]))->assertSessionHasErrors('terms');
        $this->post(route('register'), $this->touristPayload(['phone' => 'call me']))->assertSessionHasErrors('phone');

        // Contact number and where the visitor is travelling from are required:
        // the tourism office counts on both.
        $this->post(route('register'), $this->touristPayload(['phone' => null]))->assertSessionHasErrors('phone');
        $this->post(route('register'), $this->touristPayload(['home_city' => null]))->assertSessionHasErrors('home_city');
        $this->post(route('register'), $this->touristPayload(['home_country' => null]))->assertSessionHasErrors('home_country');

        $this->assertSame(0, User::count());
    }

    public function test_emails_cannot_be_reused_in_a_different_case(): void
    {
        User::factory()->create(['email' => 'bea@example.test']);

        $this->post(route('register'), $this->touristPayload(['email' => 'BEA@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    /* Resort owner ------------------------------------------------------ */

    public function test_the_owner_form_renders(): void
    {
        $this->get(route('register.owner'))->assertOk()->assertSee('Resort owner application');
    }

    public function test_an_owner_application_is_pending_and_is_not_signed_in(): void
    {
        $response = $this->post(route('register.owner'), $this->ownerPayload());

        $response->assertRedirect(route('register.owner.submitted'));
        $this->assertGuest();

        $owner = User::sole();
        $this->assertSame(UserRole::ResortOwner, $owner->role);
        $this->assertSame(UserStatus::Pending, $owner->status);

        $profile = OwnerProfile::sole();
        $this->assertSame($owner->id, $profile->user_id);
        $this->assertSame('Salt Breeze Resort', $profile->business_name);
        $this->assertNull($profile->reviewed_at);
        $this->assertNull($profile->permit_document_path);
    }

    public function test_a_pending_owner_cannot_sign_in_but_can_once_approved(): void
    {
        $this->post(route('register.owner'), $this->ownerPayload());
        $owner = User::sole();

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'seaside-2030']);
        $this->assertGuest();

        $owner->status = UserStatus::Active;
        $owner->save();

        $this->post(route('login'), ['email' => $owner->email, 'password' => 'seaside-2030'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);

        // An owner who applied through the form has never confirmed their
        // address, so the first sign-in stops at the code screen.
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));

        $code = app(EmailVerificationService::class)->send($owner->fresh());
        $this->post(route('verification.verify'), ['code' => $code]);

        $this->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));
    }

    public function test_an_owner_cannot_choose_their_own_role_or_status(): void
    {
        $this->post(route('register.owner'), $this->ownerPayload([
            'role' => 'super_admin',
            'status' => 'active',
        ]));

        $owner = User::sole();
        $this->assertSame(UserRole::ResortOwner, $owner->role);
        $this->assertSame(UserStatus::Pending, $owner->status);
    }

    public function test_a_permit_document_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');

        $this->post(route('register.owner'), $this->ownerPayload([
            'permit_document' => UploadedFile::fake()->create('permit.pdf', 120, 'application/pdf'),
        ]));

        $path = OwnerProfile::sole()->permit_document_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('owner-documents/'.User::sole()->id.'/', $path);
        $this->assertStringNotContainsString('permit.pdf', $path, 'The original filename must not be reused.');
    }

    public function test_dangerous_uploads_are_refused(): void
    {
        Storage::fake('local');

        $this->post(route('register.owner'), $this->ownerPayload([
            'permit_document' => UploadedFile::fake()->create('permit.php', 10, 'application/x-php'),
        ]))->assertSessionHasErrors('permit_document');

        $this->post(route('register.owner'), $this->ownerPayload([
            'permit_document' => UploadedFile::fake()->create('permit.svg', 10, 'image/svg+xml'),
        ]))->assertSessionHasErrors('permit_document');

        $this->post(route('register.owner'), $this->ownerPayload([
            'permit_document' => UploadedFile::fake()->create('permit.pdf', 6000, 'application/pdf'),
        ]))->assertSessionHasErrors('permit_document');

        $this->assertSame(0, User::count());
    }

    public function test_owner_validation(): void
    {
        $this->post(route('register.owner'), $this->ownerPayload(['position' => 'Chief Executive']))->assertSessionHasErrors('position');
        $this->post(route('register.owner'), $this->ownerPayload(['phone' => null]))->assertSessionHasErrors('phone');
        $this->post(route('register.owner'), $this->ownerPayload(['business_name' => null]))->assertSessionHasErrors('business_name');
        $this->post(route('register.owner'), $this->ownerPayload(['terms' => null]))->assertSessionHasErrors('terms');

        $this->assertSame(0, User::count());
        $this->assertSame(0, OwnerProfile::count());
    }

    public function test_the_confirmation_page_renders(): void
    {
        $this->get(route('register.owner.submitted'))->assertOk()->assertSee('Application received');
    }

    public function test_signed_in_users_cannot_open_the_registration_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('register'))->assertRedirect(route('dashboard'));
        $this->get(route('register.owner'))->assertRedirect(route('dashboard'));
    }
}
