<?php

namespace Tests\Feature\Database;

use App\Enums\ResortStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Destination;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Strong-Pass-2030';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'visitdasol.mto_admin.email' => 'MTO@Example.test',
            'visitdasol.mto_admin.password' => self::STRONG_PASSWORD,
            'visitdasol.demo.enabled' => false,
            'visitdasol.demo.password' => 'DemoPass2030',
        ]);
    }

    public function test_it_creates_an_active_mto_account(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = User::sole();
        $this->assertSame('mto@example.test', $admin->email);
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $admin->password));
    }

    public function test_it_requires_a_password(): void
    {
        config(['visitdasol.mto_admin.password' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set MTO_ADMIN_PASSWORD');
        $this->seed(SuperAdminSeeder::class);
    }

    public function test_it_rejects_a_weak_password(): void
    {
        config(['visitdasol.mto_admin.password' => 'password123']);

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('A weak password was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not strong enough', $e->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_it_requires_a_valid_email(): void
    {
        config(['visitdasol.mto_admin.email' => 'not-an-email']);

        $this->expectException(RuntimeException::class);
        $this->seed(SuperAdminSeeder::class);
    }

    public function test_it_never_promotes_an_existing_non_mto_account(): void
    {
        $tourist = User::factory()->create(['email' => 'mto@example.test']);

        try {
            $this->seed(SuperAdminSeeder::class);
            $this->fail('An existing tourist account was accepted as the MTO email.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non-MTO account', $e->getMessage());
        }

        $this->assertSame(UserRole::Tourist, $tourist->fresh()->role);
    }

    public function test_running_again_keeps_the_existing_password(): void
    {
        $this->seed(SuperAdminSeeder::class);

        config(['visitdasol.mto_admin.password' => 'Another-Pass-2031']);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, User::sole()->password));
    }

    public function test_demo_data_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never be seeded in production');
        // Called directly: the db:seed command itself asks for confirmation in production.
        $this->app->make(DemoDataSeeder::class)->run();
    }

    public function test_database_seeder_skips_demo_data_when_disabled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(0, Resort::count());
        $this->assertGreaterThan(0, \App\Models\Amenity::count());
        $this->assertSame(7, \App\Models\DestinationCategory::count());
    }

    public function test_demo_data_covers_every_workflow_state_and_is_labelled(): void
    {
        config(['visitdasol.demo.enabled' => true]);
        $this->seed(DatabaseSeeder::class);

        $owners = User::resortOwners()->get();
        $this->assertSame(4, $owners->where('status', UserStatus::Active)->count());
        $this->assertSame(1, $owners->where('status', UserStatus::Pending)->count());
        $this->assertSame(1, $owners->where('status', UserStatus::Rejected)->count());
        $this->assertSame(1, $owners->where('status', UserStatus::Inactive)->count());
        $this->assertTrue($owners->every(fn (User $owner) => $owner->ownerProfile()->exists()));

        /*
         * Seeded demo listings are hidden from the public by default: a town
         * with real resorts must not show fictional ones beside them. The
         * seeder itself lifts this while it works, so the sample bookings can
         * be made; by the time anyone reads the site, it is back on.
         */
        $this->assertSame(0, Resort::publiclyVisible()->count());

        // The rest of this test is about the demo data itself, so look at it
        // the way someone demonstrating an empty system would.
        config(['visitdasol.demo.listings_public' => true]);

        $this->assertSame(3, Resort::publiclyVisible()->count());
        $this->assertSame(1, Resort::pending()->count());
        $this->assertSame(1, Resort::publiclyVisible()->featured()->count());
        $this->assertSame(0, Resort::publiclyVisible()->where('name', 'like', 'Hidden Cove%')->count());
        $this->assertSame(ResortStatus::Active, Resort::where('name', 'like', 'Hidden Cove%')->sole()->status);
        $this->assertSame(7, Room::bookable()->count());

        $this->assertSame(0, User::where('role', '!=', UserRole::SuperAdmin->value)
            ->where('email', 'not like', '%@demo.visitdasol.test')->count());
        $this->assertTrue(Resort::all()->every(fn (Resort $r) => str_ends_with($r->name, '(Demo)')
            && str_starts_with($r->description, 'DEMO DATA.')));
        $this->assertTrue(Destination::all()->every(fn (Destination $d) => str_ends_with($d->name, '(Demo)')));
        $this->assertSame(1, Destination::where('status', 'inactive')->count());
        $this->assertSame(2, Announcement::published()->count());
        $this->assertTrue(Hash::check('DemoPass2030', User::where('email', 'tourist.bea@demo.visitdasol.test')->sole()->password));

        // Seeding again does not duplicate anything.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(13, User::count());
        $this->assertSame(5, Resort::count());
    }

    public function test_demo_data_requires_a_password(): void
    {
        config(['visitdasol.demo.password' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_ACCOUNT_PASSWORD');
        $this->seed(DemoDataSeeder::class);
    }
}
