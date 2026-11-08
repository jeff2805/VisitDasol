<?php

namespace Tests\Feature\Booking;

use App\Enums\IdVerificationStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The valid ID, sent with the booking request instead of after it.
 *
 * The document could already be uploaded from the reservation page once a
 * booking existed. This is the same document arriving by a different door
 * — the booking form — and it goes through the same service, so it lands
 * on the same private disk with the same status and the same log entry.
 *
 * REQUIRED, AND THAT IS THE POINT. The resort cannot check anybody in until
 * an ID has been verified, so a booking made without one was a booking that
 * could not be honoured — and the guest only discovered that afterwards,
 * from their reservation page, once the room was already being held.
 *
 * The reservation page's upload stays, because it is still the way to send
 * a replacement after one has been rejected, and the way a booking made
 * before this rule gets its ID at all.
 */
class ValidIdOnBookingFormTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($owner, 'owner')->active()->create();

        $this->room = Room::factory()->for($resort)->create([
            'capacity' => 4, 'total_rooms' => 3, 'price_per_night' => 1500,
        ]);

        $this->tourist = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'room' => $this->room->id,
            'check_in_date' => '2030-07-10',
            'check_out_date' => '2030-07-12',
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'adults' => 2,
            'children' => 0,
            'pwd_guests' => 0,
            'guests' => 2,
            'rooms_booked' => 1,
            'guests_male' => 1,
            'guests_female' => 1,
            'origin' => 'pangasinan',
            'contact_first_name' => 'Ana',
            'contact_last_name' => 'Reyes',
            'contact_phone' => '09171234567',
            'id_document' => $this->validId(),
        ], $overrides);
    }

    private function book(array $overrides = [])
    {
        return $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), $this->payload($overrides));
    }

    private function latest(): Booking
    {
        return Booking::query()->latest('id')->firstOrFail();
    }

    /* The form offers it ---------------------------------------------------- */

    public function test_the_booking_form_asks_for_a_valid_id(): void
    {
        $html = $this->actingAs($this->tourist)->get(route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-12',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('name="id_document"', $html);
        $this->assertStringContainsString('Valid ID', $html);

        // A file cannot be sent without this on the form element.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="'.preg_quote(route('tourist.bookings.store'), '/').'"[^>]*enctype="multipart\/form-data"/s',
            $html
        );

        // Marked required the same way every other required field on this
        // form is, and the input says so to a browser as well as to a reader.
        $this->assertMatchesRegularExpression(
            '/Valid ID\s*<span class="text-danger">\*<\/span>/s',
            $html
        );

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="id_document"[^>]*\srequired/s',
            $html
        );

        $this->assertStringNotContainsString('You can send it later', $html);
    }

    /* Sending one with the booking ------------------------------------------- */

    public function test_an_id_sent_with_the_booking_is_stored_and_queued(): void
    {
        $public = Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->image('licence.jpg', 800, 600)])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $booking = $this->latest();

        $this->assertSame(IdVerificationStatus::Pending, $booking->id_document_status);
        $this->assertNotNull($booking->id_document_submitted_at);
        $this->assertTrue($booking->hasIdDocument());

        // The private disk, the same folder the reservation page uses.
        $public->assertExists($booking->id_document_path);
        $this->assertStringStartsWith('booking-ids/', $booking->id_document_path);
    }

    /** A PDF is a normal way to hold a scan of an ID. */
    public function test_a_pdf_is_accepted(): void
    {
        Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->create('id.pdf', 200, 'application/pdf')])
            ->assertSessionHasNoErrors();

        $this->assertSame(IdVerificationStatus::Pending, $this->latest()->id_document_status);
    }

    /** The guest is told it went with the request. */
    public function test_the_confirmation_mentions_the_id(): void
    {
        Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->image('licence.jpg', 800, 600)]);

        $this->assertStringContainsString('valid ID was sent', (string) session('success'));
    }

    /* Skipping it ------------------------------------------------------------- */

    /**
     * NO ID, NO BOOKING, AND NO ROOM HELD.
     *
     * The check is the server's, not the browser's: the form carries
     * `novalidate`, and anything posted straight at the route skips HTML
     * validation entirely. So the test posts without a file rather than
     * trusting the attribute.
     */
    public function test_a_booking_without_an_id_is_refused(): void
    {
        $this->book(['id_document' => null])
            ->assertSessionHasErrors('id_document');

        $this->assertDatabaseCount('bookings', 0);
    }

    /** The guest is told what to do, not merely that something is wrong. */
    public function test_the_message_says_what_is_missing_and_why(): void
    {
        $this->book(['id_document' => null]);

        $this->assertSame(
            'Attach a photo or PDF of your valid ID. '
            . 'The resort cannot check you in without one.',
            session('errors')->first('id_document')
        );
    }

    /**
     * The reservation page keeps its upload.
     *
     * Nothing can reach NotSubmitted through the form any more, but a
     * booking made before this rule still can be in that state, and a
     * rejected ID needs a replacement sent the same way. Built directly
     * rather than through the form, because the form would now refuse it.
     */
    public function test_the_reservation_page_still_asks_an_older_booking(): void
    {
        $booking = Booking::factory()
            ->for($this->room)
            ->for($this->tourist, 'tourist')
            ->create(['id_document_status' => IdVerificationStatus::NotSubmitted]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Upload your valid ID');
    }

    /* Validation -------------------------------------------------------------- */

    /**
     * An executable renamed as a picture fails on its real content type —
     * the same check the reservation page's upload makes, because this is
     * the same document and two rules would mean one of them is weaker.
     */
    public function test_a_disguised_upload_is_refused_and_no_booking_is_made(): void
    {
        Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->create('licence.jpg', 20, 'application/x-httpd-php')])
            ->assertSessionHasErrors('id_document');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_other_formats_are_refused(): void
    {
        Storage::fake('local');

        foreach ([
            UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ] as $bad) {
            $this->book(['id_document' => $bad])->assertSessionHasErrors('id_document');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        Storage::fake('local');

        $tooBig = UploadedFile::fake()->create(
            'id.jpg',
            (int) config('visitdasol.uploads.document_max_kb') + 500,
            'image/jpeg'
        );

        $this->book(['id_document' => $tooBig])->assertSessionHasErrors('id_document');

        $this->assertDatabaseCount('bookings', 0);
    }

    /* Privacy ------------------------------------------------------------------ */

    /** It never reaches the public disk, whichever door it came through. */
    public function test_the_document_is_not_published(): void
    {
        Storage::fake('local');
        $public = Storage::fake('public');

        $this->book(['id_document' => UploadedFile::fake()->image('licence.jpg', 800, 600)]);

        $path = $this->latest()->id_document_path;

        Storage::disk('local')->assertExists($path);
        $public->assertMissing($path);
    }

    /** The resort can read it; a stranger cannot. */
    public function test_only_the_guest_and_their_resort_can_open_it(): void
    {
        Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->image('licence.jpg', 800, 600)]);
        $booking = $this->latest();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.id-document.show', $booking))->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('tourist.bookings.id-document.show', $booking))->assertForbidden();
    }

    /**
     * It goes into the owner's queue exactly as a later upload would, so
     * the desk has one place to look.
     */
    public function test_the_resort_sees_it_waiting_for_review(): void
    {
        Storage::fake('local');

        $this->book(['id_document' => UploadedFile::fake()->image('licence.jpg', 800, 600)]);
        $booking = $this->latest();
        $owner = $booking->resort->owner;

        $this->actingAs($owner)
            ->get(route('owner.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Verify ID')
            ->assertSee('Reject ID');
    }
}
