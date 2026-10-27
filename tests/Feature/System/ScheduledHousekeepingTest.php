<?php

namespace Tests\Feature\System;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EmailVerificationCode;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Notifications\BookingDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two jobs that run unattended.
 *
 * Both change data with nobody watching, so what they leave alone matters as
 * much as what they touch.
 */
class ScheduledHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    /* Expired one-time codes ---------------------------------------------- */

    public function test_expired_codes_are_removed_and_live_ones_are_kept(): void
    {
        $stale = User::factory()->unverified()->create();
        $current = User::factory()->unverified()->create();

        EmailVerificationCode::create([
            'user_id' => $stale->id,
            'code_hash' => Hash::make('111111'),
            'attempts' => 0,
            'expires_at' => now()->subDays(5),
            'last_sent_at' => now()->subDays(5),
        ]);

        EmailVerificationCode::create([
            'user_id' => $current->id,
            'code_hash' => Hash::make('222222'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
        ]);

        $this->artisan('visitdasol:purge-codes')->assertSuccessful();

        $this->assertDatabaseCount('email_verification_codes', 1);
        $this->assertDatabaseHas('email_verification_codes', ['user_id' => $current->id]);
    }

    public function test_a_code_that_expired_moments_ago_survives_the_grace_period(): void
    {
        $user = User::factory()->unverified()->create();

        EmailVerificationCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('333333'),
            'attempts' => 0,
            // Expired, but only just: still inside the one-day grace period.
            'expires_at' => now()->subMinutes(5),
            'last_sent_at' => now()->subMinutes(15),
        ]);

        $this->artisan('visitdasol:purge-codes')->assertSuccessful();

        $this->assertDatabaseCount('email_verification_codes', 1);
    }

    /* Finished stays ------------------------------------------------------- */

    private function stay(string $checkIn, string $checkOut, bool $confirmed = true): Booking
    {
        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();
        $room = Room::factory()->for($resort)->create();

        $booking = Booking::factory()->for($room)->for($resort)->forStay($checkIn, $checkOut);

        return ($confirmed ? $booking->confirmed() : $booking)->create();
    }

    public function test_a_stay_that_finished_is_closed_and_the_guest_is_thanked(): void
    {
        Notification::fake();

        $booking = $this->stay(now()->subDays(8)->toDateString(), now()->subDays(5)->toDateString());

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
        Notification::assertSentTo($booking->tourist, BookingDecided::class);
    }

    /**
     * A stay is over when its checkout hour has passed, so by default
     * nothing waits. That is what finishing means.
     */
    public function test_a_stay_that_ended_yesterday_is_closed_without_waiting(): void
    {
        $booking = $this->stay(now()->subDays(3)->toDateString(), now()->subDay()->toDateString());

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    /**
     * An office that wants owners to have a window before the portal
     * files a stay as finished can still have one.
     */
    public function test_a_grace_period_holds_a_stay_back_when_one_is_configured(): void
    {
        $booking = $this->stay(now()->subDays(3)->toDateString(), now()->subDay()->toDateString());

        // Checked out yesterday at noon, so 48 hours has not passed.
        $this->artisan('visitdasol:complete-stays', ['--grace' => 48])->assertSuccessful();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        config(['visitdasol.booking.auto_complete_grace_hours' => 48]);
        $this->artisan('visitdasol:complete-stays')->assertSuccessful();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    /**
     * The hour matters, not just the date. A guest checking out at noon
     * has not left at breakfast time, and the job used to run at twenty
     * past three in the morning.
     */
    public function test_a_stay_ending_later_today_is_not_closed_this_morning(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(6));

        $booking = $this->stay(
            now()->subDays(2)->toDateString(),
            now()->toDateString(),
        );

        // Checks out at noon; it is six in the morning.
        $this->artisan('visitdasol:complete-stays')->assertSuccessful();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $this->travelTo(now()->startOfDay()->addHours(13));

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    /**
     * Bookings made before guests chose their own times carry no hour of
     * their own. Falling back to the resort's standard checkout closes
     * them; refusing would leave them reading "Confirmed" for ever.
     */
    public function test_a_stay_with_no_recorded_hour_falls_back_to_the_resorts(): void
    {
        $booking = $this->stay(now()->subDays(4)->toDateString(), now()->subDays(2)->toDateString());

        $booking->forceFill(['check_in_time' => null, 'check_out_time' => null])->save();

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_a_future_stay_is_never_touched(): void
    {
        $booking = $this->stay(now()->addDays(5)->toDateString(), now()->addDays(7)->toDateString());

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_request_nobody_confirmed_is_not_quietly_completed(): void
    {
        // Pending, and its dates have passed. Closing it would invent a stay
        // that the resort never agreed to.
        $booking = $this->stay(now()->subDays(9)->toDateString(), now()->subDays(6)->toDateString(), confirmed: false);

        $this->artisan('visitdasol:complete-stays')->assertSuccessful();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $booking = $this->stay(now()->subDays(8)->toDateString(), now()->subDays(5)->toDateString());

        $this->artisan('visitdasol:complete-stays --dry-run')->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }
}
