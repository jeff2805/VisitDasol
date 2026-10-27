<?php

namespace Tests\Feature\System;

use App\Enums\BookingStatus;
use App\Enums\ResortStatus;
use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The whole system, end to end, through the same forms people use.
 *
 * One continuous story: an owner applies, the office approves them, they
 * list a resort and a room, the office publishes it, a tourist registers
 * and books, a second tourist is turned away because the only room is
 * taken, the owner confirms, the stay happens, and the office's reports
 * and audit trail reflect all of it.
 *
 * If any hand-over between the three roles breaks, this test fails.
 */
class FullLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_owner_application_to_a_completed_stay_in_the_reports(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->travelTo('2030-06-01 09:00:00');

        $mto = User::factory()->superAdmin()->create();
        $wifi = Amenity::factory()->create(['name' => 'Wi-Fi']);

        /* 1. An owner applies. -------------------------------------------- */
        $this->post(route('register.owner'), [
            'first_name' => 'Ana', 'last_name' => 'Mendoza',
            'email' => 'ana@example.test', 'phone' => '09171234567',
            'password' => 'seaside-2030', 'password_confirmation' => 'seaside-2030',
            'business_name' => 'Sunrise Cove Resort', 'position' => 'Owner',
            'business_permit_no' => 'BP-2030-0142',
            'permit_document' => UploadedFile::fake()->create('permit.pdf', 200, 'application/pdf'),
            'terms' => '1',
        ])->assertRedirect(route('register.owner.submitted'));

        $owner = User::query()->where('email', 'ana@example.test')->sole();
        $this->assertSame(UserStatus::Pending, $owner->status);

        /* 2. They cannot sign in until the office decides. ---------------- */
        $this->post(route('login'), ['email' => 'ana@example.test', 'password' => 'seaside-2030']);
        $this->assertGuest();

        /* 3. The office reviews the permit and approves. ------------------- */
        $this->actingAs($mto)->get(route('admin.owner-applications.permit', $owner))->assertOk();
        $this->actingAs($mto)->patch(route('admin.owner-applications.approve', $owner))->assertRedirect();
        $owner->refresh();
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->post(route('logout'));

        /* 4. The owner signs in and lists the resort. ----------------------- */
        $this->post(route('login'), ['email' => 'ana@example.test', 'password' => 'seaside-2030'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);

        // First sign-in: the address has never been confirmed, so the owner is
        // held at the code screen before any of the owner pages open.
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $code = app(EmailVerificationService::class)->send($owner->fresh());
        $this->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));
        $owner->refresh();

        $this->post(route('owner.resort.store'), [
            'name' => 'Sunrise Cove Resort',
            'description' => 'A small family resort facing the bay, with shaded cottages and a quiet beach.',
            'address' => 'Sitio Maligaya', 'barangay' => 'Tambobong',
            'latitude' => 16.01, 'longitude' => 119.87,
            'contact_number' => '09171234567',
            'check_in_time' => '14:00', 'check_out_time' => '12:00',
            'amenities' => [$wifi->id],
            'main_image' => UploadedFile::fake()->image('cover.jpg', 1200, 800),
        ])->assertRedirect(route('owner.resort.show'));

        $resort = Resort::query()->sole();
        $this->assertSame(ResortStatus::Pending, $resort->status);

        /* 5. And adds its only room. --------------------------------------- */
        $this->post(route('owner.rooms.store'), [
            'name' => 'Bay Cottage', 'room_type' => 'cottage',
            'capacity' => 4, 'price_per_night' => 2500, 'total_rooms' => 1,

            // Required since beds were added to the room form. Without
            // it the room was never created and the rest of the
            // lifecycle ran against a resort with nothing to book.
            'bed_count' => 2,
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('owner.rooms.index'));
        $room = $resort->rooms()->sole();

        /* 6. Nothing is public before the office approves the listing. ---- */
        $this->post(route('logout'));
        $this->get(route('resorts.show', $resort))->assertNotFound();

        /* 7. The office approves the resort; it goes live. ----------------- */
        $this->actingAs($mto)->patch(route('admin.resort-applications.approve', $resort))->assertRedirect();
        $this->post(route('logout'));

        $this->get(route('resorts.index'))->assertSee('Sunrise Cove Resort');
        $this->get(route('map'))->assertSee('Sunrise Cove Resort');

        /* 8. A tourist registers and checks the dates. --------------------- */
        $this->post(route('register'), [
            'first_name' => 'Bea', 'last_name' => 'Santos',
            'email' => 'bea@example.test', 'phone' => '09179876543',
            'home_city' => 'Quezon City', 'home_country' => 'Philippines',
            'password' => 'holiday-2030', 'password_confirmation' => 'holiday-2030',
            'terms' => '1',
        ]);
        $bea = User::query()->where('email', 'bea@example.test')->sole();
        $this->assertAuthenticatedAs($bea);

        // Sign-up emails a code; booking stays shut until it is entered.
        $this->post(route('tourist.bookings.store'), ['room' => $room->id])
            ->assertRedirect(route('verification.notice'));

        $code = app(EmailVerificationService::class)->send($bea->fresh());
        $this->post(route('verification.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));
        $bea->refresh();

        $this->get(route('resorts.show', [$resort, 'check_in' => '2030-07-10', 'check_out' => '2030-07-13', 'guests' => 3]))
            ->assertOk()->assertSee('Bay Cottage');

        /* 9. She books the only room: three nights at the listed rate. ----- */
        $this->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-10', 'check_out_date' => '2030-07-13',
            'guests' => 3, 'rooms_booked' => 1,
            'contact_name' => 'Bea Santos', 'contact_phone' => '09179876543',
            // For the office's return to the Department of Tourism.
            'guests_male' => 1, 'guests_female' => 2, 'origin' => 'ncr',
            // Tampering: an attacker-chosen price must be ignored.
            'total_amount' => 1, 'unit_price' => 1,
        ]);

        $booking = Booking::query()->sole();
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame('7500.00', $booking->total_amount);

        /* 10. A second tourist is turned away: the room is held. ----------- */
        $kevin = User::factory()->create();
        $this->actingAs($kevin)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-11', 'check_out_date' => '2030-07-12',
            'guests' => 2, 'rooms_booked' => 1,
            'contact_name' => 'Kevin Lim', 'contact_phone' => '09170000000',
            'guests_male' => 2, 'guests_female' => 0, 'origin' => 'ilocos',
        ])->assertSessionHas('error');
        $this->assertSame(1, Booking::count());

        // ...but can arrive the day Bea leaves.
        $this->actingAs($kevin)->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => '2030-07-13', 'check_out_date' => '2030-07-15',
            'guests' => 2, 'rooms_booked' => 1,
            'contact_name' => 'Kevin Lim', 'contact_phone' => '09170000000',
            'guests_male' => 2, 'guests_female' => 0, 'origin' => 'ilocos',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Booking::count());

        /* 11. The owner confirms Bea's request. ----------------------------- */
        $this->actingAs($owner)->get(route('owner.bookings.index'))->assertSee($booking->booking_reference);
        $this->actingAs($owner)->patch(route('owner.bookings.confirm', $booking))->assertRedirect();

        /* 12. Bea sees it confirmed. ---------------------------------------- */
        $this->actingAs($bea)->get(route('tourist.bookings.show', $booking))
            ->assertOk()->assertSee('Bring this reference with you');

        /* 13. The stay happens; the owner marks it completed. -------------- */
        $this->travelTo('2030-07-13 13:00:00');
        $this->actingAs($owner)->patch(route('owner.bookings.complete', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);

        /* 14. The office's reports count it. -------------------------------- */
        $summary = app(ReportService::class)->summary(
            CarbonImmutable::parse('2030-07-01'), CarbonImmutable::parse('2030-07-31')
        );
        $this->assertSame(1, $summary['confirmed_or_completed']);
        $this->assertSame(3, $summary['guests']);
        $this->assertSame(3, $summary['room_nights']);
        $this->assertEquals(7500, (float) $summary['value']);

        $this->actingAs($mto)->get(route('admin.reports.index', ['from' => '2030-07-01', 'to' => '2030-07-31']))
            ->assertOk()->assertSee('Sunrise Cove Resort');

        /* 15. And the audit trail tells the whole story, in order. --------- */
        $trail = ActivityLog::query()->orderBy('id')->pluck('action')->all();

        foreach (['owner.applied', 'owner.approved', 'resort.submitted', 'room.created',
                  'resort.approved', 'booking.created', 'booking.confirmed', 'booking.completed'] as $expected) {
            $this->assertContains($expected, $trail, "The audit trail is missing {$expected}.");
        }

        $this->assertLessThan(
            array_search('booking.confirmed', $trail, true),
            array_search('owner.approved', $trail, true),
            'Events are recorded in the order they happened.'
        );
    }
}
