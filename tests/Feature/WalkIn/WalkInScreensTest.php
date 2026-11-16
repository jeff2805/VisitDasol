<?php

namespace Tests\Feature\WalkIn;

use App\Enums\WalkInSubmissionStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\WalkInBookingService;
use App\Services\WalkInSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The walk-in screens, driven the way a resort owner and an office
 * reviewer actually drive them.
 *
 * WalkInRecordTest proves the engine. This proves the doors into it: that
 * the routes exist, that the pages render, that the forms post what the
 * services expect, and — the part worth the most — that no screen hands
 * the tourism office a figure it must not see.
 */
class WalkInScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-15 09:00:00');

        $this->owner = User::factory()->create(['role' => 'resort_owner']);
        $this->resort = Resort::factory()->active()->create(['owner_id' => $this->owner->id]);
        $this->room = Room::factory()->for($this->resort)->create([
            'total_rooms' => 3,
            'capacity' => 4,
            'price_per_night' => 1200,
            'name' => 'Seaview Cottage',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'room' => $this->room->id,
            'booking_type' => 'overnight',
            'check_in_date' => '2030-06-20',
            'check_out_date' => '2030-06-22',
            'adults' => 2,
            'children' => 1,
            'pwd_guests' => 0,
            'rooms_booked' => 1,
            'contact_name' => 'Maria Santos',
            'contact_phone' => '09171234567',
            'guest_address' => '12 Shore Road',
            'guests_male' => 1,
            'guests_female' => 2,
            'origin' => 'pangasinan',
        ], $extra);
    }

    private function record(array $extra = []): Booking
    {
        return app(WalkInBookingService::class)
            ->create($this->resort, $this->room->id, $this->payload($extra));
    }

    private function mto(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /* The owner's screens ------------------------------------------------ */

    public function test_the_walk_in_list_and_form_open_for_an_owner(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.index'))
            ->assertOk()
            ->assertSee('Walk-in records');

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.create'))
            ->assertOk()
            ->assertSee('Seaview Cottage')
            ->assertSee('For the tourism report');
    }

    public function test_the_sidebar_offers_walk_in_records(): void
    {
        $this->actingAs($this->owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Walk-in Records')
            ->assertSee(route('owner.walk-ins.index'));
    }

    public function test_an_owner_records_a_walk_in_through_the_form(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('owner.walk-ins.store'), $this->payload());

        $record = Booking::query()->walkIns()->firstOrFail();

        $response->assertRedirect(route('owner.walk-ins.show', $record));

        $this->assertNull($record->user_id);
        $this->assertSame(WalkInSubmissionStatus::Draft, $record->submission_status);
        $this->assertSame('Maria Santos', $record->contact_name);
    }

    public function test_the_form_refuses_a_stay_with_no_guest_name(): void
    {
        $this->actingAs($this->owner)
            ->post(route('owner.walk-ins.store'), $this->payload(['contact_name' => '']))
            ->assertSessionHasErrors('contact_name');

        $this->assertSame(0, Booking::query()->walkIns()->count());
    }

    public function test_an_oversold_room_is_refused_with_a_readable_message(): void
    {
        $this->record(['rooms_booked' => 3]);

        $this->actingAs($this->owner)
            ->post(route('owner.walk-ins.store'), $this->payload())
            ->assertSessionHasErrors('room');

        $this->assertSame(1, Booking::query()->walkIns()->count());
    }

    public function test_a_draft_says_what_is_missing_and_hides_the_send_button(): void
    {
        $record = $this->record(['origin' => null]);

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('Not ready to send')
            ->assertDontSee('Submit to MTO');
    }

    public function test_a_complete_draft_offers_the_send_button(): void
    {
        $record = $this->record();

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('Submit to MTO')
            ->assertDontSee('Not ready to send');
    }

    public function test_an_owner_submits_and_can_then_no_longer_edit(): void
    {
        $record = $this->record();

        $this->actingAs($this->owner)
            ->post(route('owner.walk-ins.submit', $record))
            ->assertRedirect(route('owner.walk-ins.show', $record));

        $this->assertSame(WalkInSubmissionStatus::PendingReview, $record->fresh()->submission_status);

        // The edit screen is now refused, not merely hidden.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.edit', $record))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('owner.walk-ins.update', $record), $this->payload())
            ->assertForbidden();
    }

    public function test_one_resort_cannot_open_anothers_record(): void
    {
        $record = $this->record();

        $stranger = User::factory()->create(['role' => 'resort_owner']);
        Resort::factory()->active()->create(['owner_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->get(route('owner.walk-ins.show', $record))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('owner.walk-ins.submit', $record))
            ->assertForbidden();
    }

    public function test_an_online_booking_is_not_found_on_the_walk_in_screens(): void
    {
        $online = Booking::factory()
            ->for($this->room)
            ->for(User::factory()->create(), 'tourist')
            ->confirmed()
            ->create(['resort_id' => $this->resort->id]);

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $online))
            ->assertNotFound();
    }

    /* The tourism office's screens --------------------------------------- */

    public function test_the_office_queue_shows_submitted_records_but_never_drafts(): void
    {
        $draft = $this->record(['contact_name' => 'Still A Draft']);

        $submitted = $this->record([
            'check_in_date' => '2030-07-01',
            'check_out_date' => '2030-07-03',
            'contact_name' => 'Sent Already',
        ]);

        app(WalkInSubmissionService::class)->submit($submitted, $this->owner);

        $this->actingAs($this->mto())
            ->get(route('admin.walk-ins.index'))
            ->assertOk()
            ->assertSee('Sent Already')
            ->assertDontSee('Still A Draft');

        $this->actingAs($this->mto())
            ->get(route('admin.walk-ins.show', $draft))
            ->assertNotFound();
    }

    /**
     * The rule the whole module is built around.
     */
    public function test_the_office_is_never_shown_resort_money(): void
    {
        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $page = $this->actingAs($this->mto())
            ->get(route('admin.walk-ins.show', $record))
            ->assertOk();

        // The rate, the subtotal and the total, in every spelling a page
        // could print them.
        $page->assertDontSee('1,200')
            ->assertDontSee('1200.00')
            ->assertDontSee('2,400')
            ->assertDontSee('2400.00')
            ->assertDontSee('What the stay comes to');

        $this->actingAs($this->mto())
            ->get(route('admin.walk-ins.index'))
            ->assertOk()
            ->assertDontSee('2,400');
    }

    public function test_the_owner_does_see_the_money_on_their_own_record(): void
    {
        $record = $this->record();

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('What the stay comes to');
    }

    public function test_the_office_accepts_a_record_through_the_screen(): void
    {
        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $this->actingAs($this->mto())
            ->patch(route('admin.walk-ins.accept', $record))
            ->assertRedirect(route('admin.walk-ins.show', $record));

        $this->assertSame(WalkInSubmissionStatus::Accepted, $record->fresh()->submission_status);
        $this->assertTrue(Booking::query()->reportable()->whereKey($record->id)->exists());
    }

    public function test_returning_without_remarks_is_refused(): void
    {
        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $this->actingAs($this->mto())
            ->patch(route('admin.walk-ins.return', $record), ['return_remarks' => ''])
            ->assertSessionHasErrors('return_remarks');

        $this->assertSame(WalkInSubmissionStatus::PendingReview, $record->fresh()->submission_status);
    }

    public function test_the_office_returns_a_record_and_the_owner_can_correct_it(): void
    {
        Notification::fake();

        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $this->actingAs($this->mto())
            ->patch(route('admin.walk-ins.return', $record), [
                'return_remarks' => 'The origin does not look right for this party.',
            ])
            ->assertRedirect(route('admin.walk-ins.index'));

        $this->assertSame(WalkInSubmissionStatus::Returned, $record->fresh()->submission_status);

        // The owner sees why, and may edit again.
        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.show', $record))
            ->assertOk()
            ->assertSee('The origin does not look right for this party.');

        $this->actingAs($this->owner)
            ->get(route('owner.walk-ins.edit', $record))
            ->assertOk();

        $this->actingAs($this->owner)
            ->put(route('owner.walk-ins.update', $record), $this->payload(['origin' => 'ncr']))
            ->assertRedirect(route('owner.walk-ins.show', $record));

        $this->actingAs($this->owner)
            ->post(route('owner.walk-ins.submit', $record))
            ->assertRedirect();

        $this->assertSame(WalkInSubmissionStatus::Resubmitted, $record->fresh()->submission_status);
        $this->assertSame(1, Booking::query()->walkIns()->count(), 'No duplicate booking.');
    }

    public function test_an_owner_cannot_reach_the_office_screens(): void
    {
        $record = $this->record();
        app(WalkInSubmissionService::class)->submit($record, $this->owner);

        $this->actingAs($this->owner)->get(route('admin.walk-ins.index'))->assertForbidden();
        $this->actingAs($this->owner)->patch(route('admin.walk-ins.accept', $record))->assertForbidden();
    }

    public function test_a_tourist_cannot_reach_any_walk_in_screen(): void
    {
        $record = $this->record();
        $tourist = User::factory()->create();

        $this->actingAs($tourist)->get(route('owner.walk-ins.index'))->assertForbidden();
        $this->actingAs($tourist)->get(route('admin.walk-ins.index'))->assertForbidden();
        $this->actingAs($tourist)->post(route('owner.walk-ins.submit', $record))->assertForbidden();
    }
}
