<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\IdVerificationStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use App\Notifications\IdDocumentDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The guest's valid ID, and the desk that checks it.
 *
 * WHAT THESE ARE REALLY GUARDING. The document is a photograph of somebody's
 * passport or licence. It goes to the private disk, and the ONLY thing
 * between it and the open web is a policy check in a controller — there is
 * no second gate behind that one. So the access tests here are not
 * decoration; they are the mechanism.
 */
class ValidIdVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        $this->room = Room::factory()->for($this->resort)->create(['capacity' => 4, 'total_rooms' => 3]);
        $this->tourist = User::factory()->create();
    }

    private function booking(BookingStatus $status = BookingStatus::Confirmed, array $state = []): Booking
    {
        return Booking::factory()
            ->for($this->room)
            ->for($this->tourist, 'tourist')
            ->forStay('2030-07-10', '2030-07-12')
            ->withStatus($status)
            ->create($state);
    }

    private function idFile(string $name = 'licence.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 600);
    }

    private function upload(Booking $booking, ?UploadedFile $file = null)
    {
        return $this->actingAs($this->tourist)->post(
            route('tourist.bookings.id-document.store', $booking),
            ['id_document' => $file ?? $this->idFile()]
        );
    }

    /* Submitting ----------------------------------------------------------- */

    public function test_a_guest_can_upload_an_accepted_id(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $this->upload($booking)->assertRedirect();

        $booking->refresh();

        $this->assertSame(IdVerificationStatus::Pending, $booking->id_document_status);
        $this->assertNotNull($booking->id_document_submitted_at);
        $this->assertTrue($booking->hasIdDocument());

        Storage::disk('local')->assertExists($booking->id_document_path);
    }

    /** The file lands on the private disk and nowhere a URL can reach. */
    public function test_the_document_is_stored_privately_and_never_published(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $booking = $this->booking();
        $this->upload($booking);

        $path = $booking->fresh()->id_document_path;

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        // Nothing stores a URL, so nothing can leak one into a page.
        $this->assertStringNotContainsString('http', (string) $path);
        $this->assertStringStartsWith('booking-ids/', (string) $path);
    }

    /** A PDF is a normal way to hold a scan of an ID. */
    public function test_a_pdf_is_accepted(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $pdf = UploadedFile::fake()->create('id.pdf', 200, 'application/pdf');

        $this->upload($booking, $pdf)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(IdVerificationStatus::Pending, $booking->fresh()->id_document_status);
    }

    /**
     * An executable renamed to look like a picture fails on its real content
     * type, which is the check that matters.
     */
    public function test_invalid_file_formats_are_rejected(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        foreach ([
            UploadedFile::fake()->create('shell.php', 10, 'application/x-httpd-php'),
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->create('licence.jpg', 10, 'application/x-httpd-php'),
        ] as $bad) {
            $this->upload($booking, $bad)->assertSessionHasErrors('id_document');
        }

        $this->assertSame(IdVerificationStatus::NotSubmitted, $booking->fresh()->id_document_status);
        $this->assertFalse($booking->fresh()->hasIdDocument());
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $tooBig = UploadedFile::fake()->create(
            'id.jpg',
            (int) config('visitdasol.uploads.document_max_kb') + 500,
            'image/jpeg'
        );

        $this->upload($booking, $tooBig)->assertSessionHasErrors('id_document');
    }

    /** Sending a replacement drops the old copy rather than hoarding it. */
    public function test_a_replacement_removes_the_previous_file(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $this->upload($booking);
        $first = $booking->fresh()->id_document_path;

        $this->actingAs($this->owner)->patch(
            route('owner.bookings.id-document.reject', $booking),
            ['reason' => 'The photograph is too blurred to read.']
        );

        $this->upload($booking, $this->idFile('again.jpg'));
        $second = $booking->fresh()->id_document_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    /** Nobody is made to send the same document twice. */
    public function test_a_verified_id_is_not_asked_for_again(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        $this->upload($booking);
        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));

        $this->assertFalse($booking->fresh()->acceptsIdDocument());

        $this->upload($booking)->assertForbidden();
    }

    /* Who may read it ------------------------------------------------------ */

    public function test_the_guest_and_their_resort_can_open_the_document(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.id-document.show', $booking))->assertOk();

        $this->actingAs($this->owner)
            ->get(route('owner.bookings.id-document', $booking))->assertOk();
    }

    public function test_a_signed_out_visitor_cannot_open_the_document(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->post('/logout');

        $this->get(route('owner.bookings.id-document', $booking))->assertRedirect(route('login'));
        $this->get(route('tourist.bookings.id-document.show', $booking))->assertRedirect(route('login'));
    }

    public function test_another_tourist_cannot_open_the_document(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.id-document.show', $booking))->assertForbidden();
    }

    /** THE ONE THAT MATTERS MOST: one resort cannot read another's. */
    public function test_another_resort_owner_cannot_open_the_document(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $stranger = User::factory()->resortOwner()->create();
        Resort::factory()->for($stranger, 'owner')->active()->create();

        $this->actingAs($stranger)
            ->get(route('owner.bookings.id-document', $booking))->assertForbidden();

        $this->actingAs($stranger)
            ->patch(route('owner.bookings.id-document.approve', $booking))->assertForbidden();

        $this->assertSame(IdVerificationStatus::Pending, $booking->fresh()->id_document_status);
    }

    /* Deciding ------------------------------------------------------------- */

    public function test_the_resort_can_verify_an_id_and_the_guest_is_told(): void
    {
        Storage::fake('local');
        Notification::fake();

        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.id-document.approve', $booking))
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame(IdVerificationStatus::Verified, $booking->id_document_status);
        $this->assertSame($this->owner->id, (int) $booking->id_document_verified_by);
        $this->assertNotNull($booking->id_document_verified_at);

        Notification::assertSentTo($this->tourist, IdDocumentDecided::class);
    }

    public function test_rejecting_needs_a_reason_and_records_it(): void
    {
        Storage::fake('local');
        Notification::fake();

        $booking = $this->booking();
        $this->upload($booking);

        // Without one, nothing changes.
        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.id-document.reject', $booking), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(IdVerificationStatus::Pending, $booking->fresh()->id_document_status);

        $this->actingAs($this->owner)->patch(
            route('owner.bookings.id-document.reject', $booking),
            ['reason' => 'The name on the ID does not match the booking.']
        )->assertRedirect();

        $booking->refresh();

        $this->assertSame(IdVerificationStatus::Rejected, $booking->id_document_status);
        $this->assertSame(
            'The name on the ID does not match the booking.',
            $booking->id_document_rejection_reason
        );
        $this->assertSame($this->owner->id, (int) $booking->id_document_verified_by);

        Notification::assertSentTo($this->tourist, IdDocumentDecided::class);
    }

    /** One decision per submission. */
    public function test_an_id_cannot_be_decided_twice(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.id-document.approve', $booking))
            ->assertForbidden();
    }

    /* Check-in ------------------------------------------------------------- */

    public function test_a_missing_id_prevents_check_in(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-in', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_pending_id_prevents_check_in(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)->patch(route('owner.bookings.check-in', $booking));

        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_rejected_id_prevents_check_in(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)->patch(
            route('owner.bookings.id-document.reject', $booking),
            ['reason' => 'The photograph is too blurred to read.']
        );

        $this->actingAs($this->owner)->patch(route('owner.bookings.check-in', $booking));

        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_verified_id_permits_check_in_and_records_who_did_it(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-in', $booking))
            ->assertSessionHas('success');

        $booking->refresh();

        $this->assertTrue($booking->isCheckedIn());
        $this->assertSame($this->owner->id, (int) $booking->checked_in_by);
    }

    /** A party cannot be checked into a stay that is not confirmed. */
    public function test_an_unconfirmed_booking_cannot_be_checked_into(): void
    {
        $booking = $this->booking(BookingStatus::Pending);

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-in', $booking))
            ->assertForbidden();
    }

    public function test_check_in_cannot_be_done_twice(): void
    {
        Storage::fake('local');
        $booking = $this->verifiedAndCheckedIn();

        $first = $booking->fresh()->checked_in_at;

        $this->travel(1)->hours();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-in', $booking))
            ->assertForbidden();

        $this->assertEquals($first, $booking->fresh()->checked_in_at);
    }

    /* Check-out ------------------------------------------------------------ */

    public function test_check_out_follows_check_in(): void
    {
        Storage::fake('local');
        $booking = $this->verifiedAndCheckedIn();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-out', $booking))
            ->assertSessionHas('success');

        $booking->refresh();

        $this->assertTrue($booking->isCheckedOut());
        $this->assertSame($this->owner->id, (int) $booking->checked_out_by);
    }

    /** A stay nobody was checked into has no departure to record. */
    public function test_check_out_is_refused_without_a_check_in(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);
        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-out', $booking))
            ->assertSessionHas('error');

        $this->assertNull($booking->fresh()->checked_out_at);
    }

    public function test_check_out_cannot_be_done_twice(): void
    {
        Storage::fake('local');
        $booking = $this->verifiedAndCheckedIn();

        $this->actingAs($this->owner)->patch(route('owner.bookings.check-out', $booking));
        $first = $booking->fresh()->checked_out_at;

        $this->travel(1)->hours();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-out', $booking))
            ->assertForbidden();

        $this->assertEquals($first, $booking->fresh()->checked_out_at);
    }

    /** No second copy of the same ID is asked for at the gate. */
    public function test_check_out_reuses_the_verified_id(): void
    {
        Storage::fake('local');
        $booking = $this->verifiedAndCheckedIn();

        $before = $booking->fresh()->id_document_path;

        $this->actingAs($this->owner)->patch(route('owner.bookings.check-out', $booking));

        $this->assertSame($before, $booking->fresh()->id_document_path);
        $this->assertTrue($booking->fresh()->idIsVerified());
    }

    /* The desk is the resort's own ----------------------------------------- */

    public function test_another_owner_cannot_check_in_or_out(): void
    {
        Storage::fake('local');
        $booking = $this->verifiedAndCheckedIn();

        $stranger = User::factory()->resortOwner()->create();
        Resort::factory()->for($stranger, 'owner')->active()->create();

        $this->actingAs($stranger)
            ->patch(route('owner.bookings.check-out', $booking))->assertForbidden();

        $this->assertNull($booking->fresh()->checked_out_at);
    }

    public function test_a_tourist_cannot_work_the_desk(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->tourist)
            ->patch(route('owner.bookings.id-document.approve', $booking))->assertForbidden();

        $this->actingAs($this->tourist)
            ->patch(route('owner.bookings.check-in', $booking))->assertForbidden();
    }

    /* The configured requirement ------------------------------------------- */

    /**
     * A resort with its own procedure on paper can turn the ID requirement
     * off. Who may READ a document is never configurable, and is not touched
     * by this.
     */
    public function test_check_in_without_an_id_is_possible_when_the_requirement_is_off(): void
    {
        config(['visitdasol.booking.require_id_for_check_in' => false]);

        $booking = $this->booking();

        $this->actingAs($this->owner)
            ->patch(route('owner.bookings.check-in', $booking))
            ->assertSessionHas('success');

        $this->assertTrue($booking->fresh()->isCheckedIn());
    }

    /* Both pages render what they now carry ------------------------------- */

    public function test_both_booking_pages_show_the_id_state(): void
    {
        Storage::fake('local');
        $booking = $this->booking();

        // Before anything is sent.
        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()->assertSee('Your valid ID')->assertSee('Upload your valid ID');

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()->assertSee('Valid ID and arrival')->assertSee('No valid ID submitted.');

        // Submitted, waiting on the resort.
        $this->upload($booking);

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()->assertSee('Verify ID')->assertSee('Reject ID');

        // Verified: the desk can act, and the guest is not asked again.
        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertOk()->assertSee('Check in');

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('has verified your ID', false)
            ->assertDontSee('Upload your valid ID');
    }

    /** The reason a rejected ID gives is shown to the guest who must act on it. */
    public function test_a_rejected_guest_is_told_why_on_their_own_page(): void
    {
        Storage::fake('local');
        $booking = $this->booking();
        $this->upload($booking);

        $this->actingAs($this->owner)->patch(
            route('owner.bookings.id-document.reject', $booking),
            ['reason' => 'The photograph is too blurred to read.']
        );

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('The photograph is too blurred to read.')
            ->assertSee('Send another ID');
    }

    private function verifiedAndCheckedIn(): Booking
    {
        $booking = $this->booking();
        $this->upload($booking);
        $this->actingAs($this->owner)->patch(route('owner.bookings.id-document.approve', $booking));
        $this->actingAs($this->owner)->patch(route('owner.bookings.check-in', $booking));

        return $booking;
    }
}
