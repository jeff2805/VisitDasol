<?php

namespace Tests\Feature\Owner;

use App\Enums\ResortStatus;
use App\Models\Amenity;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResortRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->resortOwner()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Salt Breeze Beach Resort',
            'description' => 'A quiet beachfront resort with rooms for couples and families, a few steps from the shore.',
            'address' => 'Sitio Malimpin',
            'barangay' => 'Tambobong',
            'latitude' => 16.008,
            'longitude' => 119.87,
            'contact_number' => '0917 123 4567',
            'email' => 'stay@saltbreeze.test',
            'website_url' => 'https://example.test/saltbreeze',
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
        ], $overrides);
    }

    public function test_an_owner_without_a_resort_is_shown_the_registration_form(): void
    {
        $this->actingAs($this->owner)->get(route('owner.resort.show'))
            ->assertRedirect(route('owner.resort.create'));

        $this->actingAs($this->owner)->get(route('owner.resort.create'))
            ->assertOk()
            ->assertSee('Register your resort')
            ->assertSee('Tambobong');
    }

    public function test_a_new_resort_is_pending_and_belongs_to_the_signed_in_owner(): void
    {
        $amenities = Amenity::factory()->count(2)->create();

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload([
            'amenities' => $amenities->pluck('id')->all(),
            // These must be ignored: an owner cannot publish their own resort.
            'status' => 'active',
            'owner_id' => User::factory()->resortOwner()->create()->id,
            'is_featured' => true,
        ]))->assertRedirect(route('owner.resort.show'));

        $resort = Resort::sole();
        $this->assertSame(ResortStatus::Pending, $resort->status);
        $this->assertSame($this->owner->id, $resort->owner_id);
        $this->assertFalse($resort->is_featured);
        $this->assertNotNull($resort->submitted_at);
        $this->assertSame('salt-breeze-beach-resort', $resort->slug);
        $this->assertSame('Dasol', $resort->municipality);
        $this->assertSame('09171234567', $resort->contact_number);
        $this->assertCount(2, $resort->amenities);

        $this->assertDatabaseHas('activity_logs', ['action' => 'resort.submitted', 'subject_id' => $resort->id]);
    }

    public function test_a_new_resort_is_not_public_until_it_is_approved(): void
    {
        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload());
        $resort = Resort::sole();

        $this->post(route('logout'));
        $this->get(route('resorts.index'))->assertOk()->assertDontSee('Salt Breeze Beach Resort');
        $this->get(route('resorts.show', $resort))->assertNotFound();
    }

    public function test_the_cover_photo_is_stored_on_the_public_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload([
            'main_image' => UploadedFile::fake()->image('beach.jpg', 1200, 800),
        ]));

        $resort = Resort::sole();
        $this->assertNotNull($resort->main_image);
        $this->assertStringStartsWith("resorts/{$resort->id}/cover/", $resort->main_image);
        $this->assertStringNotContainsString('beach.jpg', $resort->main_image);
        Storage::disk('public')->assertExists($resort->main_image);
    }

    public function test_unsafe_or_oversized_cover_photos_are_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload([
            'main_image' => UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'),
        ]))->assertSessionHasErrors('main_image');

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload([
            'main_image' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ]))->assertSessionHasErrors('main_image');

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload([
            'main_image' => UploadedFile::fake()->image('tiny.jpg', 100, 80),
        ]))->assertSessionHasErrors('main_image');

        $this->assertSame(0, Resort::count());
    }

    public function test_required_fields_and_sensible_values(): void
    {
        $cases = [
            ['name' => ''],
            ['description' => 'Too short.'],
            ['barangay' => 'Not A Barangay'],
            ['latitude' => 14.5],            // outside Dasol
            ['longitude' => 121.0],          // outside Dasol
            ['contact_number' => 'call us'],
            ['website_url' => 'javascript:alert(1)'],
            ['check_in_time' => '2pm'],
        ];

        foreach ($cases as $override) {
            $this->actingAs($this->owner)
                ->post(route('owner.resort.store'), $this->payload($override))
                ->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertSame(0, Resort::count());
    }

    public function test_an_owner_may_only_register_one_resort(): void
    {
        Resort::factory()->for($this->owner, 'owner')->create();

        $this->actingAs($this->owner)->get(route('owner.resort.create'))
            ->assertRedirect(route('owner.resort.show'));

        $this->actingAs($this->owner)->post(route('owner.resort.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(1, Resort::count());
    }

    public function test_tourists_and_the_office_cannot_use_the_owner_resort_pages(): void
    {
        foreach ([User::factory()->create(), User::factory()->superAdmin()->create()] as $other) {
            $this->actingAs($other)->get(route('owner.resort.create'))->assertForbidden();
            $this->actingAs($other)->post(route('owner.resort.store'), $this->payload())->assertForbidden();
        }

        $this->post(route('logout'));
        $this->get(route('owner.resort.create'))->assertRedirect(route('login'));
        $this->assertSame(0, Resort::count());
    }

    public function test_a_deactivated_owner_cannot_register_a_resort(): void
    {
        $owner = User::factory()->resortOwner(\App\Enums\UserStatus::Inactive)->create();

        $this->actingAs($owner)->post(route('owner.resort.store'), $this->payload())
            ->assertRedirect(route('login'));

        $this->assertSame(0, Resort::count());
    }
}
