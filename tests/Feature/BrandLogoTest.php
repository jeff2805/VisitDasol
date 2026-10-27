<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Visit Dasol logo: the normal version on light backgrounds, the
 * version without a drop shadow on the navy ones.
 */
class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_logo_files_exist(): void
    {
        foreach (['images/logo.png', 'images/logo-dark-bg.png'] as $file) {
            $this->assertFileExists(public_path($file));
            [$width, $height] = getimagesize(public_path($file));
            $this->assertSame(160, $height, "{$file} should be 160 pixels tall.");
            $this->assertGreaterThan(3 * $height, $width, "{$file} should be a wide wordmark.");
        }
    }

    public function test_the_top_bar_uses_the_normal_logo_and_names_the_site(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('images/logo.png', false)
            ->assertSee('alt="Visit Dasol"', false);
    }

    public function test_navy_areas_use_the_logo_without_a_shadow(): void
    {
        // Footer on the public site.
        $this->get(route('home'))->assertSee('images/logo-dark-bg.png', false);

        // Sign-in panel.
        $this->get(route('login'))->assertOk()->assertSee('images/logo-dark-bg.png', false);

        // Dashboard sidebar.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('images/logo-dark-bg.png', false);
    }

    public function test_error_pages_show_the_logo(): void
    {
        $this->get('/a-page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('images/logo.png', false);
    }
}
