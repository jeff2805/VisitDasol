<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The panel beside the sign-in form.
 *
 * It used to carry three promotional lines — Explore Destinations, Find
 * Accommodations, Experience the Beauty of Dasol — below the wording. They
 * are gone. Everything else on that side stays: the mark, the heading, the
 * sentence under it, the photograph and the office line at the foot.
 *
 * Asked of every page that uses layouts/auth.blade.php, because that is
 * where the list lived: it was never specific to signing in, and removing
 * it removed it from all seven.
 */
class LoginPanelTest extends TestCase
{
    use RefreshDatabase;

    public static function authPages(): array
    {
        return [
            'sign in' => ['/login'],
            'tourist registration' => ['/register'],
            'owner registration' => ['/register/owner'],
            'forgotten password' => ['/forgot-password'],
        ];
    }

    /* What was taken out --------------------------------------------------- */

    #[DataProvider('authPages')]
    public function test_the_three_promotional_lines_are_gone(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        // The labels, in the two halves the markup split them into.
        foreach ([
            'Destinations',
            'Accommodations',
            'the Beauty of Dasol',
        ] as $label) {
            $this->assertStringNotContainsString($label, $html, "\"{$label}\" is still on {$path}.");
        }

        // The list itself, its class, and the three icons that led it.
        $this->assertStringNotContainsString('vd-auth__features', $html);
        $this->assertStringNotContainsString('bi-geo-alt-fill', $html);
        $this->assertStringNotContainsString('bi-house-heart-fill', $html);
        $this->assertStringNotContainsString('bi-camera-fill', $html);
    }

    /** The rules that dressed them went with them. */
    public function test_the_stylesheet_no_longer_declares_the_list(): void
    {
        $css = preg_replace(
            '#/\*.*?\*/#s',
            '',
            file_get_contents(public_path('css/visitdasol.css'))
        );

        $this->assertStringNotContainsString('.vd-auth__features', $css);
    }

    /* What had to survive --------------------------------------------------- */

    public function test_the_panel_keeps_everything_else(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // The mark.
        $this->assertStringContainsString('vd-auth__panel', $html);
        $this->assertStringContainsString('vd-brand', $html);

        // The heading and the sentence under it, which login sets itself.
        $this->assertStringContainsString('vd-auth__panel-title', $html);
        $this->assertStringContainsString('Welcome back', $html);
        $this->assertStringContainsString('vd-auth__panel-accent', $html);
        $this->assertStringContainsString('vd-auth__panel-text', $html);
        $this->assertStringContainsString(
            'Sign in to manage your reservations, your resort listing, or the tourism office dashboard.',
            $html
        );

        // The office line at the foot.
        $this->assertStringContainsString('vd-auth__office', $html);
        $this->assertStringContainsString(config('visitdasol.contact.office'), $html);
        $this->assertStringContainsString(config('visitdasol.municipality'), $html);
    }

    /**
     * The photograph is the panel's background, set in the stylesheet
     * rather than in the markup, so removing an element above it must not
     * have disturbed it.
     */
    public function test_the_panel_keeps_its_photograph(): void
    {
        $css = file_get_contents(public_path('css/visitdasol.css'));

        $this->assertMatchesRegularExpression(
            '/\.vd-auth__panel\s*\{[^}]*background-image:\s*url\(/s',
            $css
        );

        $this->assertFileExists(public_path('images/dasol.jpg'));
    }

    /**
     * The foot of the panel is held down by the flex column, not by the
     * height of what sat above it — which is why taking the list out needed
     * no replacement spacing.
     */
    public function test_the_office_line_is_still_pushed_to_the_foot(): void
    {
        $css = preg_replace(
            '#/\*.*?\*/#s',
            '',
            file_get_contents(public_path('css/visitdasol.css'))
        );

        $this->assertMatchesRegularExpression(
            '/\.vd-auth__office\s*\{[^}]*margin-top:\s*auto/s',
            $css
        );

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 992px\)\s*\{\s*\.vd-auth__panel\s*\{[^}]*flex-direction:\s*column/s',
            $css
        );
    }

    /* The form, untouched ---------------------------------------------------- */

    public function test_the_sign_in_form_is_unchanged(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('login').'"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString(route('password.request'), $html);
    }

    /** And it still signs people in. */
    public function test_authentication_still_works(): void
    {
        $user = User::factory()->create(['email' => 'bea@example.com']);

        $this->post(route('login'), [
            'email' => 'bea@example.com',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    /** A wrong password is still refused. */
    public function test_a_bad_password_is_still_refused(): void
    {
        User::factory()->create(['email' => 'bea@example.com']);

        $this->post(route('login'), [
            'email' => 'bea@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }
}
