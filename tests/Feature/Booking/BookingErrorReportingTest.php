<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentPreference;
use App\Models\Resort;
use App\Models\ResortManualPaymentAccount;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A form that refuses a booking has to say what is wrong with it.
 *
 * The page used to print "Please fix the 4 highlighted fields and try
 * again." and leave it there, trusting each field to mark itself. When
 * one cannot — a nested key like extras.7, which no @error('extras')
 * block matches, or simply a field below the fold — the guest is told
 * to correct something and given no way on earth to find it. That is
 * not an error message, it is a riddle, and a guest meets it holding a
 * card.
 *
 * Also here: the form and the validator have to offer the same payment
 * options. Showing somebody a choice the server will refuse is the same
 * failure wearing a different hat.
 */
class BookingErrorReportingTest extends TestCase
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
        $this->tourist = User::factory()->create();

        $this->resort = Resort::factory()->active()->create(['owner_id' => $this->owner->id]);

        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'bed_count' => 3,
            'price_per_night' => 2000,
            'total_rooms' => 4,
        ]);
    }

    /**
     * The booking form's own URL.
     *
     * The dates matter: create.blade.php wraps the whole form in
     * @if ($nights < 1) and shows a "pick your dates" panel instead, so
     * a request without them renders no fields to assert on at all.
     */
    private function form(): string
    {
        return route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-12',
        ]);
    }

    /* The banner ----------------------------------------------------------- */

    /**
     * Every refusal is named, not merely counted.
     *
     * Two fields are left out here, and the page has to print what is
     * wrong with each. Counting them and saying "2 highlighted fields"
     * is what sent a guest hunting up and down a long form.
     */
    public function test_the_page_names_what_is_wrong_rather_than_counting_it(): void
    {
        $response = $this->actingAs($this->tourist)
            ->from($this->form())
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-12',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'contact_name' => 'Jenny Caole',
                'contact_phone' => '09562101985',
                'origin' => 'pangasinan',

                // The two the guest never scrolled down to.
                'guests_male' => '',
                'guests_female' => '',
            ]);

        $response->assertSessionHasErrors(['guests_male', 'guests_female']);

        $page = $this->actingAs($this->tourist)
            ->get($this->form())
            ->assertOk();

        // The sentence that used to be the whole message.
        $page->assertDontSee('highlighted fields');

        // What the guest actually needs: the messages themselves.
        $page->assertSee('Enter the number of male guests');
        $page->assertSee('Enter the number of female guests');
    }

    /** One thing wrong reads as a sentence, not as a list of one. */
    public function test_a_single_failure_is_stated_plainly(): void
    {
        $this->actingAs($this->tourist)
            ->from($this->form())
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-12',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'guests_male' => 1,
                'guests_female' => 1,
                'contact_name' => 'Jenny Caole',
                'contact_phone' => 'not a phone number',
                'origin' => 'pangasinan',
            ])
            ->assertSessionHasErrors('contact_phone');

        $this->actingAs($this->tourist)
            ->get($this->form())
            ->assertOk()
            ->assertSee('Enter a number the resort can reach you on')
            ->assertDontSee('Please fix');
    }

    /* Payment options ------------------------------------------------------ */

    /**
     * THE FORM AND THE RULES MUST ASK THE SAME QUESTION.
     *
     * BookingController::create() offers whatever
     * PaymentPreference::available($manualGcashAccount) returns. The
     * rules asked available() with no account at all, so Manual GCash
     * was never on the accepted list: a resort that had set GCash up
     * showed its guests an option the server then threw out, with
     * "The selected payment preference is invalid."
     */
    public function test_a_payment_option_the_form_offers_is_one_the_server_accepts(): void
    {
        ResortManualPaymentAccount::create([
            'resort_id' => $this->resort->id,
            'account_name' => 'Dasol Beach Resort',
            'gcash_number' => '09171234567',
            'active' => true,
        ]);

        // The form offers it.
        $page = $this->actingAs($this->tourist)
            ->get($this->form())
            ->assertOk();

        $this->assertContains(
            PaymentPreference::ManualGcash,
            $page->viewData('preferences'),
            'The form must offer Manual GCash once the resort has an active account.'
        );

        // And the server takes it.
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-12',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'guests_male' => 1,
                'guests_female' => 1,
                'contact_name' => 'Jenny Caole',
                'contact_phone' => '09562101985',
                'origin' => 'pangasinan',
                'payment_preference' => PaymentPreference::ManualGcash->value,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * And a resort WITHOUT a GCash account must not have one accepted
     * on its behalf. Widening the rule must not have widened it for
     * everybody.
     */
    public function test_manual_gcash_is_refused_where_the_resort_has_not_set_it_up(): void
    {
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-12',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'guests_male' => 1,
                'guests_female' => 1,
                'contact_name' => 'Jenny Caole',
                'contact_phone' => '09562101985',
                'origin' => 'pangasinan',
                'payment_preference' => PaymentPreference::ManualGcash->value,
            ])
            ->assertSessionHasErrors('payment_preference');
    }

    /** An account switched off is not an account. */
    public function test_an_inactive_gcash_account_does_not_open_that_option(): void
    {
        ResortManualPaymentAccount::create([
            'resort_id' => $this->resort->id,
            'account_name' => 'Dasol Beach Resort',
            'gcash_number' => '09171234567',
            'active' => false,
        ]);

        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-12',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'rooms_booked' => 1,
                'guests_male' => 1,
                'guests_female' => 1,
                'contact_name' => 'Jenny Caole',
                'contact_phone' => '09562101985',
                'origin' => 'pangasinan',
                'payment_preference' => PaymentPreference::ManualGcash->value,
            ])
            ->assertSessionHasErrors('payment_preference');
    }
}
