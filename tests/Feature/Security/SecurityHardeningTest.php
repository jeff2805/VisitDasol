<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guards for the Phase 19 security review. Each test protects one finding,
 * so a later change cannot quietly undo it.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_sends_the_security_headers(): void
    {
        $pages = [
            [null, route('home')],
            [null, route('login')],
            [User::factory()->superAdmin()->create(), route('admin.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $response = $user ? $this->actingAs($user)->get($url) : $this->get($url);

            $response->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringNotContainsString("'unsafe-eval'", $csp);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        }
    }

    public function test_signed_in_pages_are_never_kept_by_the_browser(): void
    {
        // Found in Phase 20: after signing out, Back showed the previous
        // person's reservations from the browser cache.
        $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.index'))
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache');

        $cacheControl = $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.index'))
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_public_pages_may_still_be_cached(): void
    {
        $this->assertStringNotContainsString('no-store', (string) $this->get(route('home'))->headers->get('Cache-Control'));
    }

    public function test_no_template_contains_inline_scripts_or_event_handlers(): void
    {
        // The Content Security Policy blocks these; one slipping in would
        // silently break a feature, so catch it here instead.
        foreach ($this->templates() as $file) {
            // Blade comments ({{-- ... --}}) never reach the browser.
            $contents = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $contents,
                "{$file->getRelativePathname()} has an inline <script>.");
            $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|load|error|input|keyup|keydown|focus|blur)\s*=/i', $contents,
                "{$file->getRelativePathname()} has an inline event handler.");
        }
    }

    public function test_no_template_prints_unescaped_output(): void
    {
        foreach ($this->templates() as $file) {
            $this->assertStringNotContainsString('{!!', $file->getContents(),
                "{$file->getRelativePathname()} prints unescaped output with {!! !!}.");
        }
    }

    public function test_no_controller_fills_a_model_from_the_whole_request(): void
    {
        $finder = (new Finder)->files()->in(app_path('Http/Controllers'))->name('*.php');

        foreach ($finder as $file) {
            $this->assertDoesNotMatchRegularExpression('/\$request->all\(\)|request\(\)->all\(\)/', $file->getContents(),
                "{$file->getRelativePathname()} uses the whole request; use validated() instead.");
        }
    }

    public function test_the_developer_setup_page_does_not_exist_outside_local(): void
    {
        $this->assertFalse(Route::has('dev.setup'), 'Developer routes must only be registered when APP_ENV=local.');
        $this->get('/_dev/setup')->assertNotFound();
    }

    public function test_the_expired_page_never_links_back_to_another_site(): void
    {
        $response = $this->withHeader('Referer', 'https://evil.example/phish')
            ->view('errors.419');

        $response->assertDontSee('evil.example');
        $response->assertSee(url('/'), false);
    }

    public function test_errors_do_not_reveal_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::get('/_test/explode', fn () => throw new \RuntimeException('secret database password is hunter2'))
            ->middleware('web');

        $this->get('/_test/explode')
            ->assertStatus(500)
            ->assertDontSee('hunter2')
            ->assertDontSee('RuntimeException')
            ->assertSee('Something went wrong');
    }

    public function test_session_cookies_are_hidden_from_scripts(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_the_login_form_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'bea@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), ['email' => 'bea@example.test', 'password' => 'wrong-password']);
        }

        $this->post(route('login'), ['email' => 'bea@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Too many', session('errors')->first('email'));
    }

    /**
     * @return iterable<\Symfony\Component\Finder\SplFileInfo>
     */
    private function templates(): iterable
    {
        return (new Finder)->files()->in(resource_path('views'))->name('*.blade.php');
    }
}
