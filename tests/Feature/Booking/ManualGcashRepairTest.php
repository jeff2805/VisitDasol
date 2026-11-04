<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentRequirement;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Resort;
use App\Models\ResortManualPaymentAccount;
use App\Models\ResortPaymentPolicy;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Payment terms, the resort's GCash account, and the proof a guest sends.
 *
 * THE FAULTS THIS HOLDS SHUT, each of which was live:
 *
 *   1. The booking form rendered the payment methods only when there was
 *      more than one. A resort whose single method is GCash — most of
 *      them, since QRPh needs PayMongo keys — showed none at all, posted
 *      no preference, and the guest never reached the GCash page.
 *
 *   2. The default radio was hardcoded to QRPh, so even with the fieldset
 *      drawn nothing was ticked when QRPh was not on the list.
 *
 *   3. Replacing a QR code deleted the old file BEFORE storing the new
 *      one, so a failed write left the resort with no QR at all.
 *
 *   4. The QR URL was built by hand as asset('storage/'.$path), which is
 *      wrong on a remote public disk and renders a broken image when the
 *      file has gone.
 *
 *   5. Nothing stopped a guest sending receipt after receipt for one
 *      payment while the resort was still looking at the first.
 */
class ManualGcashRepairTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->active()->for($this->owner, 'owner')->create();

        $this->resort->paymentPolicy()->create([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'allow_on_site' => true,
        ]);
    }

    private function room(): Room
    {
        return Room::factory()->for($this->resort)->create([
            'price_per_night' => 1500, 'capacity' => 4, 'total_rooms' => 2,
        ]);
    }

    private function gcash(array $state = []): ResortManualPaymentAccount
    {
        return $this->resort->manualAccount()->create($state + [
            'account_name' => 'Juan Dela Cruz',
            'gcash_number' => '09171234567',
            'active' => true,
        ]);
    }

    private function tourist(): User
    {
        return User::factory()->create();
    }

    /* Typed readers -------------------------------------------------------
     *
     * WHY THESE EXIST, rather than $booking->payments()->firstOrFail()
     * written out at each call site.
     *
     * This project declares its relations with a bare return type —
     * `public function payments(): HasMany`, 65 of them, no generics
     * anywhere. Laravel 12 types HasMany as HasMany<TRelated, TDeclaring>,
     * so with the parameters left off a static analyser has nothing to
     * resolve the builder against: firstOrFail() comes back as stdClass,
     * and every ->fresh(), ->proofs() and ->status after it is a method on
     * a plain object. That is what the editor was reporting. The code runs
     * correctly — the tests pass — but nothing could check it.
     *
     * Asking the model class directly gives Builder<Payment>, and a
     * declared return type here carries that to every caller. No cast and
     * nothing suppressed: firstOrFail() is what makes the signature true.
     *
     * Annotating the 65 relations instead would be the better repair, and
     * it is a change to production models across the whole app rather than
     * to this file.
     */

    /** The GCash payment on a booking, newest first — the relation's order. */
    private function paymentFor(Booking $booking): Payment
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('provider', 'manual_gcash')
            ->latest('id')
            ->firstOrFail();
    }

    /** The newest receipt against a payment, which is the relation's order. */
    private function latestProof(Payment $payment): PaymentProof
    {
        return PaymentProof::query()
            ->where('payment_id', $payment->getKey())
            ->latest('id')
            ->firstOrFail();
    }

    /** How many receipts have been sent against one payment. */
    private function proofCount(Payment $payment): int
    {
        return PaymentProof::query()
            ->where('payment_id', $payment->getKey())
            ->count();
    }

    /** This resort's GCash account, where the test requires one to exist. */
    private function account(): ResortManualPaymentAccount
    {
        return ResortManualPaymentAccount::query()
            ->where('resort_id', $this->resort->getKey())
            ->firstOrFail();
    }

    /** Any resort's account, or null — for the tests that assert absence. */
    private function accountOf(Resort $resort): ?ResortManualPaymentAccount
    {
        return ResortManualPaymentAccount::query()
            ->where('resort_id', $resort->getKey())
            ->first();
    }

    /** This resort's payment terms, as the model that defines fresh(). */
    private function policy(): ResortPaymentPolicy
    {
        return ResortPaymentPolicy::query()
            ->where('resort_id', $this->resort->getKey())
            ->firstOrFail();
    }

    /* A NOTE ON THE DISK ASSERTIONS BELOW.
     *
     * assertExists() and assertMissing() are real and always were — they
     * live on Illuminate\Filesystem\FilesystemAdapter, and Storage::fake()
     * hands back a LocalFilesystemAdapter that extends it.
     *
     * Storage::disk() does not say so. Its facade annotation is
     *   @method static \Illuminate\Contracts\Filesystem\Filesystem disk(...)
     * and that CONTRACT declares neither assertion, so going through
     * disk() to reach them is correct at runtime and unverifiable before
     * it. Storage::fake() is annotated with the concrete adapter instead,
     * so holding on to what it returns resolves the methods honestly. It
     * also asserts against the exact fake the test installed, rather than
     * whatever disk() resolves at that moment.
     */

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'account_name' => 'Juan Dela Cruz',
            'gcash_number' => '09171234567',
            'active' => '1',
        ], $overrides);
    }

    /* Feature 1 — payment terms -------------------------------------------- */

    public function test_an_owner_can_save_and_update_their_payment_terms(): void
    {
        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => 40,
            'deadline_hours' => 48,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 72,
            'allow_on_site' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $policy = $this->policy();

        $this->assertSame(40, (int) $policy->deposit_percent);
        $this->assertSame(48, (int) $policy->deadline_hours);
        $this->assertTrue((bool) $policy->allow_on_site);

        // Saving again is a new edition, so a booking can say which
        // terms it was made under.
        $before = (int) $policy->version;

        $this->actingAs($this->owner)->put(route('owner.payment-policy.update'), [
            'requirement' => 'deposit',
            'deposit_percent' => 25,
            'deadline_hours' => 12,
            'deadline_reference' => 'confirmation',
            'last_minute_rule' => 'full',
            'last_minute_hours' => 72,
        ]);

        $policy = $this->policy();

        $this->assertSame(25, (int) $policy->deposit_percent);
        $this->assertFalse((bool) $policy->allow_on_site, 'An unticked box must read as off.');
        $this->assertGreaterThan($before, (int) $policy->version);
    }

    /** The configured percentage is what the guest is actually asked for. */
    public function test_the_deposit_is_calculated_from_the_configured_percentage(): void
    {
        $this->resort->paymentPolicy()->update(['deposit_percent' => 40]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $this->resort->fresh()->paymentTerms(),
            '5000.00',
            \Carbon\CarbonImmutable::today()->addDays(30),
        );

        $this->assertSame('2000.00', $terms->dueNow);
        $this->assertSame('3000.00', $terms->balance, 'The balance is the rest of the bill.');
        $this->assertSame('5000.00', $terms->total);
    }

    /** An imminent arrival is governed by the last-minute rule instead. */
    public function test_the_last_minute_rule_asks_for_the_full_amount(): void
    {
        $this->resort->paymentPolicy()->update([
            'last_minute_rule' => 'full',
            'last_minute_hours' => 72,
        ]);

        $terms = app(\App\Services\Payments\PaymentPolicyService::class)->terms(
            $this->resort->fresh()->paymentTerms(),
            '5000.00',
            \Carbon\CarbonImmutable::today()->addDay(),
        );

        $this->assertTrue($terms->lastMinute);
        $this->assertSame('5000.00', $terms->dueNow);
        $this->assertSame('0.00', $terms->balance);
    }

    /** A tourist cannot reach another resort's terms. */
    public function test_a_tourist_cannot_change_payment_terms(): void
    {
        $this->actingAs($this->tourist())
            ->put(route('owner.payment-policy.update'), ['requirement' => 'none'])
            ->assertForbidden();
    }

    /* Feature 3 — the method reaches the guest ------------------------------ */

    /**
     * THE ONE THAT HID THE WHOLE FEATURE. GCash is the only method here,
     * because PayMongo is not configured in the test environment.
     */
    public function test_a_single_payment_method_is_still_offered(): void
    {
        $this->gcash();
        $room = $this->room();

        $html = $this->actingAs($this->tourist())->get(route('tourist.bookings.create', [
            'room' => $room->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(22)->toDateString(),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('value="manual_gcash"', $html);

        // And it is ticked, or the form posts no preference at all.
        $this->assertMatchesRegularExpression(
            '/value="manual_gcash"[^>]*\schecked\b/s',
            $html,
            'The only method on offer has to be the one selected.'
        );
    }

    /** An account switched off is not offered and is not accepted. */
    public function test_a_disabled_account_is_neither_offered_nor_accepted(): void
    {
        $this->gcash(['active' => false]);
        $room = $this->room();

        $html = $this->actingAs($this->tourist())->get(route('tourist.bookings.create', [
            'room' => $room->id,
            'check_in' => today()->addDays(20)->toDateString(),
            'check_out' => today()->addDays(22)->toDateString(),
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="manual_gcash"', $html);

        // And the server refuses it even when posted by hand.
        $this->actingAs($this->tourist())->post(route('tourist.bookings.store'), [
            'room' => $room->id,
            'check_in_date' => today()->addDays(20)->toDateString(),
            'check_out_date' => today()->addDays(22)->toDateString(),
            'guests' => 2, 'rooms_booked' => 1,
            'contact_name' => 'Bea Santos', 'contact_phone' => '09171234567',
            'guests_male' => 1, 'guests_female' => 1, 'origin' => 'pangasinan',
            'payment_preference' => 'manual_gcash',
        ])->assertSessionHasErrors('payment_preference');
    }

    /** An account with the switch on but nothing to pay into is not offered. */
    public function test_an_account_with_no_number_is_not_usable(): void
    {
        $this->gcash()->forceFill(['gcash_number' => ''])->save();

        // Re-read, so this is the stored row and not the one just edited.
        $this->assertFalse($this->account()->isUsable());
    }

    /* Feature 2 and 5 — the account and its QR ------------------------------ */

    public function test_an_owner_can_save_their_gcash_details(): void
    {
        $this->actingAs($this->owner)
            ->put(route('owner.manual-gcash.update'), $this->settings())
            ->assertRedirect()->assertSessionHas('status');

        $account = $this->account();

        $this->assertSame('Juan Dela Cruz', $account->account_name);
        $this->assertSame('09171234567', $account->gcash_number);
        $this->assertTrue($account->active);
    }

    /** The same number written for a human is accepted and normalised. */
    public function test_the_number_is_normalised_and_nonsense_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put(route('owner.manual-gcash.update'), $this->settings(['gcash_number' => '0917 123 4567']))
            ->assertSessionHasNoErrors();

        $this->assertSame('09171234567', $this->account()->gcash_number);

        // 63 and 0 are the same number.
        $this->actingAs($this->owner)
            ->put(route('owner.manual-gcash.update'), $this->settings(['gcash_number' => '639171234567']));

        $this->assertSame('09171234567', $this->account()->gcash_number);

        // And prose is refused rather than silently stored as "".
        $this->actingAs($this->owner)
            ->put(route('owner.manual-gcash.update'), $this->settings(['gcash_number' => 'ask me on Facebook']))
            ->assertSessionHasErrors('gcash_number');
    }

    public function test_a_qr_code_uploads_and_resolves_to_a_url(): void
    {
        $public = Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.manual-gcash.update'), $this->settings([
            'qr_image' => UploadedFile::fake()->image('qr.png', 400, 400),
        ]))->assertSessionHasNoErrors();

        $account = $this->account();

        $this->assertNotNull($account->qr_image);
        $public->assertExists($account->qr_image);
        $this->assertStringContainsString($account->qr_image, (string) $account->qr_url);
    }

    public function test_replacing_a_qr_code_removes_only_the_old_one(): void
    {
        $public = Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.manual-gcash.update'), $this->settings([
            'qr_image' => UploadedFile::fake()->image('first.png', 400, 400),
        ]));

        $first = $this->account()->qr_image;

        $this->actingAs($this->owner)->put(route('owner.manual-gcash.update'), $this->settings([
            'qr_image' => UploadedFile::fake()->image('second.png', 400, 400),
        ]));

        $second = $this->account()->qr_image;

        $this->assertNotSame($first, $second);
        $public->assertMissing($first);
        $public->assertExists($second);
    }

    /**
     * THE DESTRUCTIVE ORDER. A save that carries no new file must leave
     * the existing QR exactly where it is — this is the shape of the bug
     * that deleted it before the replacement was written.
     */
    public function test_saving_other_details_keeps_the_existing_qr(): void
    {
        $public = Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.manual-gcash.update'), $this->settings([
            'qr_image' => UploadedFile::fake()->image('qr.png', 400, 400),
        ]));

        $qr = $this->account()->qr_image;

        $this->actingAs($this->owner)->put(
            route('owner.manual-gcash.update'),
            $this->settings(['account_name' => 'Juana Dela Cruz'])
        );

        $account = $this->account();

        $this->assertSame($qr, $account->qr_image, 'A save with no new file must not touch the QR.');
        $this->assertSame('Juana Dela Cruz', $account->account_name);
        $public->assertExists($qr);
    }

    /**
     * A row can outlive its file; the page must not show a broken image.
     *
     * Asked of a LOCAL public disk, because that is the only driver whose
     * files can be checked for cheaply. The branch is the trait's.
     */
    public function test_a_missing_qr_file_resolves_to_null_rather_than_a_broken_image(): void
    {
        config(['filesystems.disks.public.driver' => 'local']);
        Storage::fake('public');

        $account = $this->gcash(['qr_image' => 'manual-gcash-qr/gone.png']);

        $this->assertNull($account->qr_url);
    }

    /**
     * THE DEPLOYMENT BUG. This install runs its public disk on S3
     * (Supabase Storage, PUBLIC_DISK_DRIVER=s3). The URL was built by
     * hand as asset('storage/'.$path), which addresses the local
     * public/storage link — a path that does not exist on a remote disk,
     * so every QR code on the deployed site was a broken image.
     *
     * The disk has to be asked for its own address.
     */
    public function test_a_remote_public_disk_is_asked_for_its_own_url(): void
    {
        config([
            'filesystems.disks.public.driver' => 's3',
            'filesystems.disks.public.url' => 'https://cdn.example.test/bucket',
        ]);

        $account = $this->gcash(['qr_image' => 'manual-gcash-qr/qr.png']);

        $url = (string) $account->qr_url;

        $this->assertStringStartsWith('https://cdn.example.test/bucket', $url);
        $this->assertStringNotContainsString('/storage/manual-gcash-qr', $url,
            'A remote disk must not be addressed through the local storage link.');
    }

    public function test_a_qr_with_no_file_at_all_is_null(): void
    {
        $this->assertNull($this->gcash()->qr_url);
    }

    /** An executable renamed as a picture is refused on its real type. */
    public function test_a_disguised_upload_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner)->put(route('owner.manual-gcash.update'), $this->settings([
            'qr_image' => UploadedFile::fake()->create('qr.png', 20, 'application/x-httpd-php'),
        ]))->assertSessionHasErrors('qr_image');

        $this->assertNull($this->accountOf($this->resort));
    }

    /** One owner can never reach another's GCash settings. */
    public function test_another_owner_cannot_change_these_settings(): void
    {
        $this->gcash();

        $stranger = User::factory()->resortOwner()->create();
        $strangerResort = Resort::factory()->active()->for($stranger, 'owner')->create();

        $this->actingAs($stranger)
            ->put(route('owner.manual-gcash.update'), $this->settings(['account_name' => 'Taken Over']))
            ->assertRedirect();

        // Their own account was made; this resort's is untouched.
        $this->assertSame('Juan Dela Cruz', $this->account()->account_name);
        $this->assertSame('Taken Over', $this->accountOf($strangerResort)?->account_name);
    }

    public function test_a_tourist_cannot_reach_the_gcash_settings(): void
    {
        $this->actingAs($this->tourist())
            ->get(route('owner.manual-gcash.edit'))->assertForbidden();
    }

    /* Feature 4 — the proof -------------------------------------------------- */

    private function confirmedBookingWithPayment(): Booking
    {
        $this->gcash();

        $booking = Booking::factory()
            ->for($this->room())
            ->for($this->tourist(), 'tourist')
            ->forStay('2030-07-10', '2030-07-12')
            ->confirmed()
            ->create(['total_amount' => '3000.00', 'amount_due_now' => '900.00']);

        $this->actingAs($booking->tourist)
            ->post(route('tourist.manual-payments.start', $booking));

        return $booking->fresh();
    }

    /** Sending a receipt must not mark anything paid. */
    public function test_uploading_proof_does_not_mark_the_payment_paid(): void
    {
        Storage::fake('local');

        $booking = $this->confirmedBookingWithPayment();
        $payment = $this->paymentFor($booking);

        // The deposit, not the whole bill.
        $this->assertSame('900.00', (string) $payment->amount);

        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF123456',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        )->assertRedirect();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PaymentProof::SUBMITTED, $this->latestProof($payment)->status);
        $this->assertNull($payment->fresh()->paid_at);
    }

    /** THE DUPLICATE GUARD. One receipt at a time. */
    public function test_a_second_receipt_is_refused_while_one_is_waiting(): void
    {
        Storage::fake('local');

        $booking = $this->confirmedBookingWithPayment();

        $send = fn (string $reference) => $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => $reference,
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        );

        $send('REF111111');
        $send('REF222222')->assertSessionHas('error');

        $this->assertSame(1, $this->proofCount($this->paymentFor($booking)));
    }

    /** The owner approves, and only then is it paid. */
    public function test_the_owner_approves_and_that_is_what_settles_it(): void
    {
        Storage::fake('local');

        $booking = $this->confirmedBookingWithPayment();
        $payment = $this->paymentFor($booking);

        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF333333',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        );

        $proof = $this->latestProof($payment);

        $this->actingAs($this->owner)
            ->post(route('owner.payment-proofs.approve', $proof))
            ->assertRedirect();

        // Re-read once, rather than four times: these are four facts about
        // one row as it stands after the approval.
        $payment = $this->paymentFor($booking);
        $proof = $this->latestProof($payment);

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentProof::APPROVED, $proof->status);
        $this->assertSame($this->owner->id, (int) $proof->reviewed_by);
        $this->assertNotNull($proof->reviewed_at);
    }

    /** Rejecting needs a reason, and leaves the payment unpaid. */
    public function test_rejecting_records_a_reason_and_settles_nothing(): void
    {
        Storage::fake('local');

        $booking = $this->confirmedBookingWithPayment();
        $payment = $this->paymentFor($booking);

        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF444444',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        );

        $proof = $this->latestProof($payment);

        $this->actingAs($this->owner)->post(
            route('owner.payment-proofs.reject', $proof),
            ['review_note' => 'The reference number does not match our records.']
        )->assertRedirect();

        $this->assertSame(PaymentProof::REJECTED, $proof->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        // And a corrected receipt may now be sent.
        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF555555',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('again.png', 600, 800),
            ]
        )->assertSessionHasNoErrors();

        $this->assertSame(2, $this->proofCount($payment));
    }

    /** Another resort's owner cannot approve or read this proof. */
    public function test_another_owner_cannot_touch_this_proof(): void
    {
        Storage::fake('local');

        $booking = $this->confirmedBookingWithPayment();

        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF666666',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        );

        $proof = $this->latestProof($this->paymentFor($booking));

        $stranger = User::factory()->resortOwner()->create();
        Resort::factory()->active()->for($stranger, 'owner')->create();

        $this->actingAs($stranger)
            ->post(route('owner.payment-proofs.approve', $proof))->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('owner.payment-proofs.image', $proof))->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $this->paymentFor($booking)->status);
    }

    /** The receipt is on the private disk, not served by the web server. */
    public function test_the_receipt_is_stored_privately(): void
    {
        $private = Storage::fake('local');
        $public = Storage::fake('public');

        $booking = $this->confirmedBookingWithPayment();

        $this->actingAs($booking->tourist)->post(
            route('tourist.manual-payments.submit', $booking),
            [
                'reference_number' => 'REF777777',
                'sender_name' => 'Bea Santos',
                'amount_sent' => '900.00',
                'proof_image' => UploadedFile::fake()->image('receipt.png', 600, 800),
            ]
        );

        $path = $this->latestProof($this->paymentFor($booking))->proof_image;

        $private->assertExists($path);
        $public->assertMissing($path);
    }
}
