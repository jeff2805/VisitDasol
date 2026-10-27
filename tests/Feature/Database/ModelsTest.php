<?php

namespace Tests\Feature\Database;

use App\Enums\BookingStatus;
use App\Enums\ResortStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_status_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        new User([
            'first_name' => 'Eve',
            'last_name' => 'Intruder',
            'email' => 'eve@example.test',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function test_new_users_default_to_pending_tourists(): void
    {
        $user = new User(['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.test', 'password' => 'secret-password']);
        $user->save();
        $user->refresh();

        $this->assertSame(UserRole::Tourist, $user->role);
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertFalse($user->isActive());
    }

    public function test_passwords_are_hashed_and_hidden(): void
    {
        $user = User::factory()->create(['password' => 'plain-text-123']);

        $this->assertNotSame('plain-text-123', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('plain-text-123', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_user_name_accessors_and_role_helpers(): void
    {
        $owner = User::factory()->resortOwner()->create(['first_name' => 'Carmela', 'last_name' => 'reyes']);

        $this->assertSame('Carmela reyes', $owner->full_name);
        $this->assertSame('CR', $owner->initials);
        $this->assertTrue($owner->isResortOwner());
        $this->assertFalse($owner->isTourist());
        $this->assertTrue($owner->hasRole(UserRole::Tourist, UserRole::ResortOwner));
        $this->assertNotNull($owner->ownerProfile);
    }

    public function test_emails_are_unique(): void
    {
        User::factory()->create(['email' => 'same@example.test']);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'same@example.test']);
    }

    public function test_resort_owner_fields_are_protected(): void
    {
        $this->expectException(MassAssignmentException::class);

        new Resort(['name' => 'Sneaky Resort', 'owner_id' => 1, 'status' => 'active']);
    }

    public function test_resort_defaults_and_relationships(): void
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->create();
        $amenities = Amenity::factory()->count(2)->create();
        $resort->amenities()->attach($amenities);
        Room::factory()->count(2)->for($resort)->create();

        $resort->refresh();

        $this->assertSame(ResortStatus::Pending, $resort->status);
        $this->assertSame('Dasol', $resort->municipality);
        $this->assertSame('Pangasinan', $resort->province);
        $this->assertTrue($resort->owner->is($owner));
        $this->assertTrue($owner->resort->is($resort));
        $this->assertTrue($resort->isOwnedBy($owner));
        $this->assertFalse($resort->isOwnedBy(User::factory()->resortOwner()->create()));
        $this->assertCount(2, $resort->rooms);
        $this->assertCount(2, $resort->amenities);
        $this->assertStringEndsWith('images/placeholder.svg', $resort->main_image_url);
        $this->assertStringContainsString('Dasol, Pangasinan', $resort->full_address);
    }

    public function test_slugs_are_unique_and_stable(): void
    {
        $first = Resort::factory()->create(['name' => 'Osmeña Beach Resort']);
        $second = Resort::factory()->create(['name' => 'Osmeña Beach Resort']);

        $this->assertSame('osmena-beach-resort', $first->slug);
        $this->assertSame('osmena-beach-resort-2', $second->slug);

        $first->update(['name' => 'Renamed Resort']);
        $this->assertSame('osmena-beach-resort', $first->fresh()->slug);

        $announcement = Announcement::factory()->create(['title' => 'Holiday Office Hours']);
        $this->assertSame('holiday-office-hours', $announcement->slug);
    }

    public function test_pivot_rejects_duplicate_amenities(): void
    {
        $resort = Resort::factory()->create();
        $amenity = Amenity::factory()->create();
        $resort->amenities()->attach($amenity);

        $this->expectException(QueryException::class);
        $resort->amenities()->attach($amenity);
    }

    public function test_room_names_are_unique_within_a_resort_only(): void
    {
        $resortA = Resort::factory()->create();
        $resortB = Resort::factory()->create();

        Room::factory()->for($resortA)->create(['name' => 'Deluxe']);
        Room::factory()->for($resortB)->create(['name' => 'Deluxe']);
        $this->assertSame(2, Room::where('name', 'Deluxe')->count());

        $this->expectException(QueryException::class);
        Room::factory()->for($resortA)->create(['name' => 'Deluxe']);
    }

    public function test_booking_uses_reference_as_route_key_and_stores_plain_dates(): void
    {
        $booking = Booking::factory()->forStay('2030-10-10', '2030-10-13')->create();

        $this->assertSame('booking_reference', $booking->getRouteKeyName());
        $this->assertMatchesRegularExpression('/^VD-20301010-[A-HJ-NP-Z2-9]{5}$/', $booking->booking_reference);
        $this->assertSame(3, $booking->nights);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame($booking->room->resort_id, $booking->resort_id);
        $this->assertSame(
            number_format((float) $booking->room->price_per_night * 3, 2, '.', ''),
            $booking->total_amount
        );

        $raw = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame('2030-10-10', $raw->check_in_date);
        $this->assertSame('2030-10-13', $raw->check_out_date);
        $this->assertSame('2030-10-10', $booking->fresh()->check_in_date->toDateString());
    }

    public function test_booking_history_blocks_deleting_rooms_and_resorts(): void
    {
        $booking = Booking::factory()->create();

        $this->expectException(QueryException::class);
        $booking->room->delete();
    }

    public function test_activity_log_uses_short_morph_names_and_survives_its_subject(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $resort = Resort::factory()->create();

        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'resort.approved',
            'subject_type' => $resort->getMorphClass(),
            'subject_id' => $resort->id,
            'description' => 'Resort approved',
            'properties' => ['from' => 'pending', 'to' => 'active'],
        ]);

        $this->assertSame('resort', $log->subject_type);
        $this->assertTrue($log->subject->is($resort));
        $this->assertSame('active', $log->fresh()->properties['to']);
        $this->assertNull($log->getUpdatedAtColumn());

        $admin->delete();
        $this->assertNull($log->fresh()->user_id);
    }
}
