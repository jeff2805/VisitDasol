<?php

namespace Tests\Feature\Console;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeploymentCommandsTest extends TestCase
{
    use RefreshDatabase;

    /* visitdasol:check-production ---------------------------------------- */

    public function test_the_check_fails_a_development_setup(): void
    {
        config(['app.debug' => true]);

        $this->artisan('visitdasol:check-production')
            ->expectsOutputToContain('APP_ENV is production')
            ->expectsOutputToContain('Set APP_DEBUG=false')
            ->assertExitCode(1);
    }

    public function test_the_check_catches_demo_accounts(): void
    {
        User::factory()->create(['email' => 'tourist.bea@'.config('visitdasol.demo.email_domain')]);

        $this->artisan('visitdasol:check-production')
            ->expectsOutputToContain('1 demo accounts found')
            ->assertExitCode(1);
    }

    public function test_the_check_passes_a_properly_configured_live_site(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => 'https://visitdasol.example',
            'session.secure' => true,
            'mail.default' => 'smtp',
            'visitdasol.demo.enabled' => false,
            'visitdasol.mto_admin.password' => null,
        ]);
        User::factory()->superAdmin()->create();

        // The photo link is a normal setup step (php artisan storage:link).
        if (! file_exists(public_path('storage'))) {
            $this->artisan('storage:link');
        }

        $this->artisan('visitdasol:check-production')
            ->doesntExpectOutputToContain('FAIL')
            ->assertExitCode(0);
    }

    public function test_https_without_a_secure_cookie_is_a_failure(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'app.url' => 'https://visitdasol.example', 'session.secure' => false]);
        User::factory()->superAdmin()->create();

        $this->artisan('visitdasol:check-production')
            ->expectsOutputToContain('Set SESSION_SECURE_COOKIE=true')
            ->assertExitCode(1);
    }

    /* visitdasol:demo-reset ------------------------------------------------ */

    public function test_the_reset_refuses_to_run_on_a_live_site(): void
    {
        $this->app['env'] = 'production';
        $keep = User::factory()->create();

        $this->artisan('visitdasol:demo-reset', ['--force' => true])
            ->expectsOutputToContain('Refusing to run')
            ->assertExitCode(1);

        $this->assertModelExists($keep);
    }

    public function test_the_reset_asks_before_erasing_anything(): void
    {
        $this->configureDemo();
        $keep = User::factory()->create();

        $this->artisan('visitdasol:demo-reset')
            ->expectsConfirmation('This erases ALL accounts, resorts, bookings, and uploaded photos. Continue?', 'no')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(0);

        $this->assertModelExists($keep);
    }

    public function test_the_reset_needs_the_demo_password(): void
    {
        $this->configureDemo();
        config(['visitdasol.demo.password' => null]);

        $this->artisan('visitdasol:demo-reset', ['--force' => true])
            ->expectsOutputToContain('DEMO_ACCOUNT_PASSWORD')
            ->assertExitCode(1);
    }

    public function test_the_reset_loads_the_demo_data_and_clears_old_uploads(): void
    {
        $this->configureDemo();
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('resorts/99/old-photo.jpg', 'x');
        Storage::disk('local')->put('owner-documents/99/old-permit.pdf', 'x');
        $stranger = User::factory()->create(['email' => 'left.over@example.test']);

        $this->artisan('visitdasol:demo-reset', ['--force' => true])
            ->expectsOutputToContain('Demo data is ready')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => 'left.over@example.test']);
        $this->assertDatabaseHas('users', ['email' => 'owner.saltbreeze@'.config('visitdasol.demo.email_domain')]);
        $this->assertDatabaseHas('users', ['email' => 'mto@example.test']);
        $this->assertGreaterThan(0, Booking::count());
        Storage::disk('public')->assertMissing('resorts/99/old-photo.jpg');
        Storage::disk('local')->assertMissing('owner-documents/99/old-permit.pdf');
    }

    /* Upload limit page ------------------------------------------------------ */

    public function test_an_upload_over_the_server_limit_gets_a_friendly_page(): void
    {
        $this->view('errors.413')
            ->assertSee('That upload was too large')
            ->assertSee('3 MB');
    }

    private function configureDemo(): void
    {
        config([
            'visitdasol.demo.password' => 'DemoPass2030',
            'visitdasol.mto_admin.email' => 'mto@example.test',
            'visitdasol.mto_admin.password' => 'Strong-Pass-2030',
        ]);
    }
}
