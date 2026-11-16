<?php

namespace Tests\Feature\WalkIn;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\WalkInSubmissionStatus;
use App\Exceptions\BookingException;
use App\Exceptions\WalkInException;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Notifications\WalkInReturned;
use App\Services\AvailabilityService;
use App\Services\WalkInBookingService;
use App\Services\WalkInSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Walk-in records: the resort's own guests, and the tourism office's review.
 *
 * The two things this module must never get wrong, and which most of these
 * tests are really about:
 *
 *   1. A walk-in holds its room the moment it is saved, draft or not, and
 *      keeps holding it when the office sends it back.
 *   2. An unaccepted walk-in is never counted in the Department of Tourism
 *      return, however real the stay behind it is.
 */
class WalkInRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    private Room $room;

    private WalkInBookingService $records;

    private WalkInSubmissionService $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-15 09:00:00');

        $this->owner = User::factory()->create(['role' => 'resort_owner']);
        $this->resort = Resort::factory()->active()->create(['owner_id' => $this->owner->id]);
        $this->room = Room::factory()->for($this->resort)->create([
            'total_rooms' => 2,
            'capacity' => 4,
            'price_per_night' => 1000,
        ]);

        $this->records = app(WalkInBookingService::class);
        $this->office = app(WalkInSubmissionService::class);
    }

    /** @param array<string, mixed> $extra */
    private function walkIn(array $extra = []): Booking
    {
        return $this->records->create($this->resort, $this->room->id, array_merge([
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'adults' => 2,
            'children' => 1,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Maria Santos',
            'contact_phone' => '09171234567',
            'guest_address' => '12 Shore Road, Dasol',
            'guests_male' => 1,
            'guests_female' => 2,
            'origin' => 'pangasinan',
        ], $extra));
    }

    /* Recording ---------------------------------------------------------- */

    public function test_a_walk_in_is_recorded_without_a_tourist_account(): void
    {
        $booking = $this->walkIn();

        $this->assertNull($booking->user_id, 'A walk-in guest has no account.');
        $this->assertSame(BookingSource::WalkIn, $booking->booking_source);
        $this->assertSame(WalkInSubmissionStatus::Draft, $booking->submission_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotEmpty($booking->booking_reference);
        $this->assertSame('12 Shore Road, Dasol', $booking->guest_address);
    }

    public function test_the_bill_comes_from_the_same_pricer_as_an_online_stay(): void
    {
        $booking = $this->walkIn();

        // Two nights, one room, 1000 a night.
        $this->assertSame(2, $booking->nights);
        $this->assertSame(3, $booking->guests);
        $this->assertSame('2000.00', $booking->total_amount);
    }

    /**
     * The whole point of recording a walk-in: the room really is occupied.
     */
    public function test_a_draft_walk_in_holds_its_room_immediately(): void
    {
        $availability = app(AvailabilityService::class);

        $this->assertSame(2, $availability->availableUnits($this->room, '2030-06-20', '2030-06-22'));

        $booking = $this->walkIn();

        $this->assertSame(WalkInSubmissionStatus::Draft, $booking->submission_status);
        $this->assertSame(
            1,
            $availability->availableUnits($this->room, '2030-06-20', '2030-06-22'),
            'A draft walk-in still holds its room.'
        );
    }

    public function test_a_walk_in_cannot_oversell_the_room_type(): void
    {
        $this->walkIn(['rooms_booked' => 2]);

        $this->expectException(BookingException::class);
        $this->walkIn();
    }

    public function test_a_walk_in_cannot_take_a_room_an_online_guest_holds(): void
    {
        Booking::factory()
            ->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->confirmed()
            ->create([
                'resort_id' => $this->resort->id,
                'check_in_date' => '2030-06-20',
                'check_out_date' => '2030-06-22',
                'rooms_booked' => 2,
            ]);

        $this->expectException(BookingException::class);
        $this->walkIn();
    }

    /**
     * The online form refuses the past. This one must not: the guest is
     * standing at the desk, or arrived yesterday.
     */
    public function test_a_walk_in_may_be_recorded_for_a_stay_already_under_way(): void
    {
        $booking = $this->walkIn([
            'check_in_date' => '2030-06-14',
            'check_out_date' => '2030-06-16',
        ]);

        $this->assertSame('2030-06-14', $booking->check_in_date->toDateString());
    }

    public function test_the_sex_split_must_add_up_to_the_party(): void
    {
        $this->expectException(ValidationException::class);

        $this->walkIn(['guests_male' => 1, 'guests_female' => 1]);
    }

    public function test_a_resort_cannot_record_against_another_resorts_room(): void
    {
        $other = Room::factory()->for(Resort::factory()->active())->create();

        $this->expectException(BookingException::class);
        $this->records->create($this->resort, $other->id, [
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'contact_name' => 'Someone',
            'contact_phone' => '09170000000',
        ]);
    }

    /* The tourism office ------------------------------------------------- */

    public function test_a_draft_is_not_in_the_tourism_return_but_an_accepted_record_is(): void
    {
        $booking = $this->walkIn();

        $inReturn = fn (): bool => Booking::query()->reportable()->whereKey($booking->id)->exists();

        $this->assertFalse($inReturn(), 'A draft is not reported.');

        $this->office->submit($booking, $this->owner);
        $this->assertFalse($inReturn(), 'Pending review is not reported either.');

        $this->office->accept($booking->fresh(), $this->mto());
        $this->assertTrue($inReturn(), 'An accepted record is reported.');
    }

    public function test_online_bookings_are_always_in_the_tourism_return(): void
    {
        $online = Booking::factory()
            ->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->confirmed()
            ->create(['resort_id' => $this->resort->id]);

        $this->assertTrue(Booking::query()->reportable()->whereKey($online->id)->exists());
        $this->assertNull($online->submission_status);
    }

    public function test_submitting_moves_it_to_pending_review_and_records_who_sent_it(): void
    {
        $booking = $this->office->submit($this->walkIn(), $this->owner);

        $this->assertSame(WalkInSubmissionStatus::PendingReview, $booking->submission_status);
        $this->assertNotNull($booking->submitted_at);
        $this->assertSame(1, $booking->submissions()->count());
    }

    public function test_a_record_cannot_be_submitted_twice(): void
    {
        $booking = $this->office->submit($this->walkIn(), $this->owner);

        $this->expectException(WalkInException::class);
        $this->office->submit($booking, $this->owner);
    }

    public function test_an_incomplete_record_cannot_be_sent_to_the_office(): void
    {
        $booking = $this->walkIn(['origin' => null]);

        $this->expectException(WalkInException::class);
        $this->office->submit($booking, $this->owner);
    }

    public function test_returning_requires_remarks_and_tells_the_owner(): void
    {
        Notification::fake();

        $booking = $this->office->submit($this->walkIn(), $this->owner);

        try {
            $this->office->returnForCorrection($booking, $this->mto(), '   ');
            $this->fail('A record was returned with no reason.');
        } catch (WalkInException) {
            // Expected.
        }

        $returned = $this->office->returnForCorrection(
            $booking->fresh(),
            $this->mto(),
            'The origin looks wrong for this party.'
        );

        $this->assertSame(WalkInSubmissionStatus::Returned, $returned->submission_status);
        $this->assertSame('The origin looks wrong for this party.', $returned->return_remarks);

        Notification::assertSentTo($this->owner, WalkInReturned::class);
    }

    /**
     * The rule the module exists to protect.
     */
    public function test_a_returned_record_keeps_its_room(): void
    {
        $availability = app(AvailabilityService::class);
        $booking = $this->office->submit($this->walkIn(), $this->owner);

        $this->office->returnForCorrection($booking->fresh(), $this->mto(), 'Please check the counts.');

        $this->assertSame(
            1,
            $availability->availableUnits($this->room, '2030-06-20', '2030-06-22'),
            'Returning a record must never release the guest\'s room.'
        );
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_returned_record_is_corrected_and_resubmitted_without_a_duplicate(): void
    {
        $booking = $this->office->submit($this->walkIn(), $this->owner);
        $this->office->returnForCorrection($booking->fresh(), $this->mto(), 'Wrong origin.');

        $corrected = $this->records->update($booking->fresh(), [
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'room' => $this->room->id,
            'adults' => 2,
            'children' => 1,
            'rooms_booked' => 1,
            'contact_name' => 'Maria Santos',
            'contact_phone' => '09171234567',
            'guests_male' => 1,
            'guests_female' => 2,
            'origin' => 'ncr',
        ]);

        $resubmitted = $this->office->submit($corrected, $this->owner);

        $this->assertSame(WalkInSubmissionStatus::Resubmitted, $resubmitted->submission_status);
        $this->assertNull($resubmitted->return_remarks, 'A stale complaint must not follow the record back.');
        $this->assertSame(1, Booking::query()->walkIns()->count(), 'Resubmitting must not duplicate the booking.');
        $this->assertSame(3, $resubmitted->submissions()->count(), 'Submitted, returned, resubmitted.');
    }

    public function test_accepting_records_who_decided_and_when(): void
    {
        $mto = $this->mto();
        $booking = $this->office->submit($this->walkIn(), $this->owner);

        $accepted = $this->office->accept($booking->fresh(), $mto);

        $this->assertSame(WalkInSubmissionStatus::Accepted, $accepted->submission_status);
        $this->assertSame($mto->id, $accepted->reviewed_by);
        $this->assertNotNull($accepted->accepted_at);
    }

    public function test_an_accepted_record_can_no_longer_be_changed(): void
    {
        $booking = $this->office->submit($this->walkIn(), $this->owner);
        $accepted = $this->office->accept($booking->fresh(), $this->mto());

        $this->assertFalse($accepted->isEditableByOwner());

        $this->expectException(WalkInException::class);
        $this->records->update($accepted, [
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'contact_name' => 'Changed',
            'contact_phone' => '09170000000',
        ]);
    }

    public function test_the_office_cannot_decide_on_a_draft(): void
    {
        $booking = $this->walkIn();

        $this->expectException(WalkInException::class);
        $this->office->accept($booking, $this->mto());
    }

    public function test_an_online_booking_is_never_treated_as_a_walk_in(): void
    {
        $online = Booking::factory()
            ->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->confirmed()
            ->create(['resort_id' => $this->resort->id]);

        $this->expectException(WalkInException::class);
        $this->office->submit($online, $this->owner);
    }

    /* Authorisation ------------------------------------------------------ */

    public function test_an_owner_cannot_touch_another_resorts_records(): void
    {
        $booking = $this->walkIn();
        $stranger = User::factory()->create(['role' => 'resort_owner']);
        Resort::factory()->active()->create(['owner_id' => $stranger->id]);

        $this->assertTrue($this->owner->can('updateWalkIn', $booking));
        $this->assertFalse($stranger->can('updateWalkIn', $booking));
        $this->assertFalse($stranger->can('submitWalkIn', $booking));
    }

    public function test_only_the_office_reviews_and_only_what_is_waiting(): void
    {
        $mto = $this->mto();
        $booking = $this->walkIn();

        $this->assertFalse($mto->can('reviewWalkIn', $booking), 'A draft is not the office\'s to decide.');
        $this->assertFalse($this->owner->can('reviewWalkIn', $booking));

        $submitted = $this->office->submit($booking, $this->owner);

        $this->assertTrue($mto->can('reviewWalkIn', $submitted));
        $this->assertFalse($this->owner->can('reviewWalkIn', $submitted), 'A resort may not accept its own record.');
    }

    public function test_the_office_may_never_edit_a_resorts_figures(): void
    {
        $booking = $this->office->submit($this->walkIn(), $this->owner);

        $this->assertFalse(
            $this->mto()->can('updateWalkIn', $booking),
            'A reviewer who could edit the numbers would make the review meaningless.'
        );
    }

    private function mto(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }
}
