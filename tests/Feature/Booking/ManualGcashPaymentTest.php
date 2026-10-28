<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Resort;
use App\Models\ResortManualPaymentAccount;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paying a resort by GCash, with a screenshot for proof.
 *
 * WHY THIS FILE EXISTS. The whole flow had no test, and so a form that
 * posted to a route name the router had never heard of —
 * manual-payments.submit, which is really tourist.manual-payments.submit
 * — sat in the repository happily. route() throws on an unknown name, so
 * the page did not merely lose its button: it returned a 500, and no
 * guest could send GCash proof to any resort at all. Nothing but opening
 * the page finds a fault like that, which is what a test is for.
 *
 * Two things are guarded here above all:
 *
 *   1. The page renders, for each state it can be in.
 *   2. The receipt screenshot stays private. It carries the guest's
 *      name, their mobile number, the amount and the reference, and it
 *      must never sit on a disk the web server hands out unasked.
 */
class ManualGcashPaymentTest extends TestCase
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

        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create([
            'contact_number' => '09181234567',
        ]);

        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'total_rooms' => 4,
            'price_per_night' => 1000,
        ]);
    }

    private function account(array $overrides = []): ResortManualPaymentAccount
    {
        return ResortManualPaymentAccount::create($overrides + [
            'resort_id' => $this->resort->id,
            'account_name' => 'Dasol Beach Resort',
            'gcash_number' => '09171234567',
            'instructions' => 'Send the exact amount and keep your receipt.',
            'active' => true,
        ]);
    }

    /** A confirmed booking with a pending manual GCash payment against it. */
    private function pending(): Payment
    {
        $booking = Booking::factory()
            ->for($this->resort)
            ->for($this->room)
            ->for($this->tourist, 'tourist')
            ->confirmed()
            ->create([
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-15',
                'total_amount' => 5000,
            ]);

        return $booking->payments()->create([
            'payment_reference' => 'VDP-MANUAL-TEST0001',
            'provider' => 'manual_gcash',
            'amount' => '5000.00',
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
        ]);
    }

    /* The page ------------------------------------------------------------- */

    public function test_the_guest_can_open_the_gcash_page_and_it_has_a_working_form(): void
    {
        $this->account();
        $payment = $this->pending();
        $booking = $payment->booking;

        $response = $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $booking));

        $response->assertOk();

        // The account the guest is being asked to send money to.
        $response->assertSee('Dasol Beach Resort');
        $response->assertSee('09171234567');
        $response->assertSee('Send the exact amount');

        /*
         * The regression this file was written for. The form must post
         * somewhere the router recognises; asserting the URL rather than
         * the name is what catches route() having thrown.
         */
        $response->assertSee(route('tourist.manual-payments.submit', $booking), false);
    }

    public function test_the_page_says_so_when_the_resort_has_published_no_gcash_details(): void
    {
        // No account row at all, so the controller passes null for it.
        $payment = $this->pending();

        $response = $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $payment->booking));

        $response->assertOk();
        $response->assertSee('has not published its GCash details');

        // And tells the guest how to get on with it.
        $response->assertSee('09181234567');

        /*
         * No upload form. Asking somebody to prove a payment they had
         * no number to send is worse than showing them nothing.
         */
        $response->assertDontSee('Send proof');
    }

    public function test_an_inactive_account_counts_as_no_account(): void
    {
        $this->account(['active' => false]);
        $payment = $this->pending();

        $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $payment->booking))
            ->assertOk()
            ->assertSee('has not published its GCash details');
    }

    public function test_a_booking_with_no_manual_payment_started_is_not_a_page(): void
    {
        $this->account();

        $booking = Booking::factory()
            ->for($this->resort)
            ->for($this->room)
            ->for($this->tourist, 'tourist')
            ->confirmed()
            ->create();

        $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $booking))
            ->assertNotFound();
    }

    public function test_somebody_elses_booking_is_refused(): void
    {
        $this->account();
        $payment = $this->pending();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('tourist.manual-payments.show', $payment->booking))
            ->assertForbidden();
    }

    /* Sending the proof ---------------------------------------------------- */

    public function test_the_guest_can_send_proof_and_the_resort_sees_it_waiting(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->account();
        $payment = $this->pending();

        $this->actingAs($this->tourist)
            ->post(route('tourist.manual-payments.submit', $payment->booking), [
                'reference_number' => '0123456789012',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '5000.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png'),
            ])
            ->assertRedirect(route('tourist.manual-payments.show', $payment->booking))
            ->assertSessionHasNoErrors();

        $proof = PaymentProof::sole();

        $this->assertSame('0123456789012', $proof->reference_number);
        $this->assertSame('Bea Santos', $proof->sender_name);
        $this->assertSame(PaymentProof::SUBMITTED, $proof->status);
        $this->assertSame($payment->id, $proof->payment_id);

        /*
         * THE RECEIPT IS PRIVATE. On the public disk the web server
         * hands this file to anybody who has the URL, with no session
         * and no ownership check — the authorisation in the image()
         * action would have been guarding a door standing open beside
         * it.
         */
        Storage::disk('local')->assertExists($proof->proof_image);
        Storage::disk('public')->assertMissing($proof->proof_image);

        // Sending proof is not the same as having been paid.
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_the_same_gcash_reference_cannot_be_sent_twice(): void
    {
        Storage::fake('local');

        $this->account();
        $payment = $this->pending();

        $send = fn () => $this->actingAs($this->tourist)
            ->post(route('tourist.manual-payments.submit', $payment->booking), [
                'reference_number' => '0123456789012',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '5000.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png'),
            ]);

        $send()->assertSessionHasNoErrors();
        $send()->assertSessionHasErrors('reference_number');

        $this->assertSame(1, PaymentProof::count());
    }

    public function test_a_stranger_cannot_send_proof_against_a_booking(): void
    {
        Storage::fake('local');

        $this->account();
        $payment = $this->pending();

        $this->actingAs(User::factory()->create())
            ->post(route('tourist.manual-payments.submit', $payment->booking), [
                'reference_number' => '0123456789012',
                'sender_name' => 'Not Their Booking',
                'amount_sent' => '5000.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png'),
            ])
            ->assertForbidden();

        $this->assertSame(0, PaymentProof::count());
    }

    /* The resort's side ---------------------------------------------------- */

    private function submittedProof(): PaymentProof
    {
        Storage::fake('local');

        $this->account();
        $payment = $this->pending();

        $this->actingAs($this->tourist)
            ->post(route('tourist.manual-payments.submit', $payment->booking), [
                'reference_number' => '0123456789012',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '5000.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png'),
            ])
            ->assertSessionHasNoErrors();

        return PaymentProof::sole();
    }

    public function test_the_owner_sees_the_proof_waiting_and_can_open_the_screenshot(): void
    {
        $proof = $this->submittedProof();

        $this->actingAs($this->owner)
            ->get(route('owner.payment-proofs.index'))
            ->assertOk()
            ->assertSee('0123456789012')
            ->assertSee('Bea Santos');

        $this->actingAs($this->owner)
            ->get(route('owner.payment-proofs.image', $proof))
            ->assertOk();
    }

    public function test_the_screenshot_is_not_served_to_anybody_else(): void
    {
        $proof = $this->submittedProof();

        // Another resort owner, who has no business with this guest.
        $otherOwner = User::factory()->resortOwner()->create();

        $this->actingAs($otherOwner)
            ->get(route('owner.payment-proofs.image', $proof))
            ->assertForbidden();

        // The guest's own account is not the resort's review screen.
        $this->actingAs($this->tourist)
            ->get(route('owner.payment-proofs.image', $proof))
            ->assertForbidden();

        auth()->logout();

        $this->get(route('owner.payment-proofs.image', $proof))
            ->assertRedirect(route('login'));
    }

    public function test_a_proof_whose_file_has_gone_is_a_404_and_not_an_error(): void
    {
        $proof = $this->submittedProof();

        Storage::disk('local')->delete($proof->proof_image);

        $this->actingAs($this->owner)
            ->get(route('owner.payment-proofs.image', $proof))
            ->assertNotFound();
    }

    public function test_approving_the_proof_marks_the_payment_paid(): void
    {
        $proof = $this->submittedProof();

        $this->actingAs($this->owner)
            ->post(route('owner.payment-proofs.approve', $proof))
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentProof::APPROVED, $proof->refresh()->status);
        $this->assertSame($this->owner->id, $proof->reviewed_by);

        $payment = $proof->payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);

        // The guest's page now says so rather than asking again.
        $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $payment->booking))
            ->assertOk()
            ->assertSee('Payment confirmed by the resort')
            ->assertDontSee('Send proof');
    }

    public function test_rejecting_the_proof_tells_the_guest_why(): void
    {
        $proof = $this->submittedProof();

        $this->actingAs($this->owner)
            ->post(route('owner.payment-proofs.reject', $proof), [
                'review_note' => 'No such reference in our GCash history.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentProof::REJECTED, $proof->refresh()->status);

        // Still pending, not failed: the guest may send a new screenshot.
        $this->assertSame(PaymentStatus::Pending, $proof->payment->refresh()->status);

        $this->actingAs($this->tourist)
            ->get(route('tourist.manual-payments.show', $proof->payment->booking))
            ->assertOk()
            ->assertSee('No such reference in our GCash history.')
            ->assertSee('Send proof');
    }

    public function test_another_owner_cannot_approve_or_reject_the_proof(): void
    {
        $proof = $this->submittedProof();

        $otherOwner = User::factory()->resortOwner()->create();
        Resort::factory()->for($otherOwner, 'owner')->active()->create();

        $this->actingAs($otherOwner)
            ->post(route('owner.payment-proofs.approve', $proof))
            ->assertForbidden();

        $this->actingAs($otherOwner)
            ->post(route('owner.payment-proofs.reject', $proof), ['review_note' => 'Mine now.'])
            ->assertForbidden();

        $this->assertSame(PaymentProof::SUBMITTED, $proof->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $proof->payment->refresh()->status);
    }
}
