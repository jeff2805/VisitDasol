<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingStatusManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The waiting-requests tile on the owner dashboard.
 *
 * A request holds the owner's rooms until it is answered, so a figure the
 * owner has to notice and act on is worth more than a figure they read. The
 * tile is therefore the link to the list, filtered to the ones waiting, and
 * it turns red while anybody is actually waiting.
 *
 * The count is the one already behind the dashboard: Booking::forOwner()
 * with the pending status. Nothing here adds a second way to ask.
 */
class PendingRequestNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($resort)->create(['total_rooms' => 5, 'capacity' => 4]);
    }

    private function booking(BookingStatus $status, string $in = '2030-07-10', string $out = '2030-07-13'): Booking
    {
        return Booking::factory()->for($this->room)->forStay($in, $out)->withStatus($status)->create();
    }

    private function dashboard()
    {
        return $this->actingAs($this->owner)->get(route('owner.dashboard'));
    }

    /* The count ----------------------------------------------------------- */

    public function test_it_counts_the_pending_requests(): void
    {
        $this->booking(BookingStatus::Pending);
        $this->booking(BookingStatus::Pending, '2030-07-20', '2030-07-22');

        $this->dashboard()
            ->assertOk()
            ->assertViewHas('waitingCount', 2)
            ->assertSee('2 pending')
            ->assertSee('Requests waiting for you');
    }

    /**
     * Only pending. A confirmed, rejected, cancelled or completed stay is an
     * answered one, and an owner chased to answer it again would stop
     * trusting the figure.
     */
    public function test_only_pending_requests_are_counted(): void
    {
        $this->booking(BookingStatus::Pending);
        $this->booking(BookingStatus::Confirmed, '2030-07-20', '2030-07-22');
        $this->booking(BookingStatus::Rejected, '2030-07-24', '2030-07-26');
        $this->booking(BookingStatus::Cancelled, '2030-07-28', '2030-07-30');
        $this->booking(BookingStatus::Completed, '2030-05-01', '2030-05-03');

        $this->dashboard()->assertOk()->assertViewHas('waitingCount', 1);
    }

    /** Another owner's requests are somebody else's problem. */
    public function test_another_owners_requests_are_never_counted(): void
    {
        $this->booking(BookingStatus::Pending);

        $other = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($other, 'owner')->active()->create();
        $otherRoom = Room::factory()->for($otherResort)->create();
        Booking::factory()->for($otherRoom)->forStay('2030-07-10', '2030-07-13')
            ->withStatus(BookingStatus::Pending)->create();

        $this->assertSame(2, Booking::query()->withStatus(BookingStatus::Pending)->count());

        $this->dashboard()->assertOk()->assertViewHas('waitingCount', 1);
        $this->actingAs($other)->get(route('owner.dashboard'))->assertOk()->assertViewHas('waitingCount', 1);
    }

    /* What it looks like --------------------------------------------------- */

    /** Red, and the whole tile is the link. */
    public function test_the_tile_is_a_red_link_to_the_pending_list(): void
    {
        $this->booking(BookingStatus::Pending);

        $html = $this->dashboard()->assertOk()->getContent();

        preg_match('/<a class="[^"]*vd-stat[^"]*"[^>]*>/', $html, $m);
        $tile = $m[0] ?? '';

        $this->assertNotSame('', $tile, 'The waiting-requests tile should be a link.');
        $this->assertStringContainsString('vd-stat--alert', $tile, 'It should carry the red state.');
        $this->assertStringContainsString('vd-stat--link', $tile);
        $this->assertStringContainsString(
            'href="'.route('owner.bookings.index', ['status' => 'pending']).'"',
            htmlspecialchars_decode($tile),
            'It should open the list already filtered to pending.'
        );
    }

    /**
     * Zero still shows the figure, and still opens the list — but not in red.
     * A tile that is red every day is furniture nobody reads.
     */
    public function test_zero_shows_a_calm_tile_that_still_opens_the_list(): void
    {
        $this->booking(BookingStatus::Confirmed);

        $html = $this->dashboard()->assertOk()
            ->assertViewHas('waitingCount', 0)
            ->assertSee('Nothing to answer. New requests appear here.')
            ->assertDontSee('pending</span>', false)
            ->getContent();

        $this->assertStringNotContainsString('vd-stat--alert', $html);
        $this->assertStringContainsString('vd-stat--link', $html);
    }

    /** The red is not the only thing saying this needs answering. */
    public function test_the_badge_says_pending_in_words(): void
    {
        $this->booking(BookingStatus::Pending);

        $this->dashboard()->assertOk()->assertSee('vd-stat__badge', false)->assertSee('1 pending');
    }

    /* It follows the database --------------------------------------------- */

    /** Answering a request takes it off the count. */
    public function test_confirming_and_rejecting_lower_the_count(): void
    {
        $first = $this->booking(BookingStatus::Pending);
        $second = $this->booking(BookingStatus::Pending, '2030-07-20', '2030-07-22');

        $this->dashboard()->assertViewHas('waitingCount', 2);

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.confirm', $first))
            ->assertRedirect();

        $this->dashboard()->assertViewHas('waitingCount', 1);

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.reject', $second), ['owner_remarks' => 'Fully booked that week.'])
            ->assertRedirect();

        $this->dashboard()->assertViewHas('waitingCount', 0)->assertSee('Nothing to answer. New requests appear here.');

        $this->assertSame(BookingStatus::Confirmed, $first->fresh()->status);
        $this->assertSame(BookingStatus::Rejected, $second->fresh()->status);
    }

    /* Security ------------------------------------------------------------- */

    /**
     * The destination is guarded server-side, not by hiding the tile. A
     * tourist typing the URL gets nothing, signed out gets the sign-in page.
     */
    public function test_the_destination_is_closed_to_everybody_else(): void
    {
        $target = route('owner.bookings.index', ['status' => 'pending']);

        $this->get($target)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get($target)->assertForbidden();

        $this->actingAs($this->owner)->get($target)->assertOk();
    }

    /**
     * Following the tile shows this owner's waiting requests and no other
     * owner's, even though both are pending on the same dates.
     */
    public function test_following_the_tile_shows_only_this_owners_requests(): void
    {
        $mine = $this->booking(BookingStatus::Pending);
        $answered = $this->booking(BookingStatus::Confirmed, '2030-07-20', '2030-07-22');

        $other = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($other, 'owner')->active()->create();
        $otherRoom = Room::factory()->for($otherResort)->create();
        $theirs = Booking::factory()->for($otherRoom)->forStay('2030-07-10', '2030-07-13')
            ->withStatus(BookingStatus::Pending)->create();

        $this->actingAs($this->owner)
            ->get(route('owner.bookings.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee($mine->booking_reference)
            ->assertDontSee($theirs->booking_reference)
            ->assertDontSee($answered->booking_reference);
    }

    /** An owner still cannot answer somebody else's request. */
    public function test_an_owner_cannot_answer_another_owners_request(): void
    {
        $other = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($other, 'owner')->active()->create();
        $otherRoom = Room::factory()->for($otherResort)->create();
        $theirs = Booking::factory()->for($otherRoom)->forStay('2030-07-10', '2030-07-13')
            ->withStatus(BookingStatus::Pending)->create();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.confirm', $theirs))
            ->assertForbidden();

        $this->assertSame(BookingStatus::Pending, $theirs->fresh()->status);
    }
}
