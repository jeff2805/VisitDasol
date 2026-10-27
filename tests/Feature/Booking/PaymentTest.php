<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paying for a reservation online.
 *
 * No gateway is ever really called: Http::fake() answers in their place, so
 * these run with no API keys, no network, and no money.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Stands in for the whsk_... value PayMongo issues per webhook. */
    private const WEBHOOK_SECRET = 'whsk_pretend_secret';

    private User $tourist;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        // Keys present, so the gateways count as switched on.
        config([
            'services.paymongo.secret' => 'sk_test_pretend',
            'services.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
            'services.paymongo.methods' => ['gcash', 'card'],
            'services.paypal.client_id' => 'pretend-id',
            'services.paypal.secret' => 'pretend-secret',
            'services.paypal.mode' => 'sandbox',
            'services.webhooks.tolerance' => 300,
        ]);

        $this->tourist = User::factory()->create();

        $room = Room::factory()->for(Resort::factory()->active())->create(['capacity' => 4, 'total_rooms' => 3]);

        $this->booking = Booking::factory()
            ->for($room)
            ->for($room->resort)
            ->for($this->tourist, 'tourist')
            ->confirmed()
            ->create(['total_amount' => '8025.00']);
    }

    private function fakePayMongo(string $paymentStatus = 'paid'): void
    {
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_pretend_123',
                    'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_pretend_123'],
                ],
            ]),
            'api.paymongo.com/v1/checkout_sessions/*' => Http::response([
                'data' => [
                    'id' => 'cs_pretend_123',
                    'attributes' => [
                        'payments' => [[
                            'attributes' => ['status' => $paymentStatus, 'source' => ['type' => 'gcash']],
                        ]],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Posts a webhook signed the way PayMongo signs one: HMAC-SHA256 over
     * "<timestamp>.<raw body>" with the webhook secret, in a
     * "t=..,te=..,li=.." header.
     *
     * The raw JSON is sent as the body rather than an array, because the
     * signature covers the exact bytes and re-encoding would change them.
     */
    private function payMongoWebhook(
        array $body,
        ?int $timestamp = null,
        ?string $secret = null,
        ?string $rawBodyOverride = null,
    ): \Illuminate\Testing\TestResponse {
        $payload = json_encode($body);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret ?? self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            route('payments.webhook', 'paymongo'),
            [], [], [],
            [
                'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=notusedintestmode",
                'CONTENT_TYPE' => 'application/json',
            ],
            // Sending different bytes than were signed proves tampering fails.
            $rawBodyOverride ?? $payload,
        );
    }

    /* What is offered ---------------------------------------------------- */

    public function test_a_reservation_the_resort_has_not_confirmed_cannot_be_paid(): void
    {
        $this->booking->forceFill(['status' => BookingStatus::Pending])->save();

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Pay online');

        $this->actingAs($this->tourist)
            ->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_confirmed_reservation_offers_the_configured_gateways(): void
    {
        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertSee('Pay online')
            // Named from the configured method list, so the button cannot
            // offer a wallet PayMongo's checkout page would not show.
            ->assertSee('GCash')
            ->assertSee('Pay with GCash or card through PayMongo')
            ->assertSee('PayPal');
    }

    public function test_nothing_is_offered_when_no_gateway_is_configured(): void
    {
        config(['services.paymongo.secret' => null, 'services.paypal.client_id' => null, 'services.paypal.secret' => null]);

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Pay online')
            ->assertSee('Payment is collected by the resort');
    }

    /* Paying -------------------------------------------------------------- */

    public function test_starting_a_payment_sends_the_guest_to_the_gateway(): void
    {
        $this->fakePayMongo();

        $this->actingAs($this->tourist)
            ->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo'])
            ->assertRedirect('https://checkout.paymongo.com/cs_pretend_123');

        $payment = Payment::sole();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('cs_pretend_123', $payment->provider_reference);
        // The amount is read from the booking, never from the request.
        $this->assertSame('8025.00', $payment->amount);
    }

    public function test_the_amount_cannot_be_chosen_by_the_guest(): void
    {
        $this->fakePayMongo();

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), [
            'provider' => 'paymongo',
            'amount' => '1.00',
            'total_amount' => '1.00',
        ]);

        $this->assertSame('8025.00', Payment::sole()->amount);
    }

    public function test_returning_credits_the_reservation_only_on_the_gateways_word(): void
    {
        $this->fakePayMongo('paid');

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);
        $this->actingAs($this->tourist)->get(route('tourist.payments.return', $this->booking))
            ->assertRedirect(route('tourist.bookings.show', $this->booking))
            ->assertSessionHas('success');

        $payment = Payment::sole();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('gcash', $payment->method);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_coming_back_without_paying_credits_nothing(): void
    {
        // The gateway says the payment is still unpaid, however the guest got here.
        $this->fakePayMongo('awaiting_payment_method');

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);
        $this->actingAs($this->tourist)->get(route('tourist.payments.return', $this->booking))
            ->assertSessionHas('warning');

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
        $this->assertNull(Payment::sole()->paid_at);
    }

    public function test_a_reservation_is_only_ever_credited_once(): void
    {
        $this->fakePayMongo('paid');

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);

        // Return, refresh, and a webhook arriving all land on the same payment.
        $this->actingAs($this->tourist)->get(route('tourist.payments.return', $this->booking));
        $this->actingAs($this->tourist)->get(route('tourist.payments.return', $this->booking));
        $this->payMongoWebhook(['data' => ['id' => 'cs_pretend_123']])->assertOk();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, Payment::settled()->count());
    }

    public function test_a_second_tap_on_pay_reuses_the_open_checkout(): void
    {
        $this->fakePayMongo();

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);
        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_once_paid_there_is_nothing_left_to_pay(): void
    {
        $this->fakePayMongo('paid');

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);
        $this->actingAs($this->tourist)->get(route('tourist.payments.return', $this->booking));

        $this->actingAs($this->tourist)->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertSee('paid in full')
            ->assertDontSee('Pay online');
    }

    /* Other people -------------------------------------------------------- */

    public function test_nobody_can_pay_for_someone_elses_reservation(): void
    {
        $this->fakePayMongo();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo'])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('tourist.payments.return', $this->booking))
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_webhook_records_a_payment_the_guest_never_came_back_from(): void
    {
        $this->fakePayMongo('paid');

        $this->actingAs($this->tourist)->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);

        // The guest closed the tab, so the return URL is never loaded.
        $this->payMongoWebhook([
            'data' => ['attributes' => ['data' => ['id' => 'cs_pretend_123']]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, Payment::sole()->status);
    }

    public function test_an_unknown_gateway_is_not_answered(): void
    {
        $this->post(route('payments.webhook', 'stripe'))->assertNotFound();
    }

    /* Webhook signatures --------------------------------------------------
     *
     * The webhook has no session, no CSRF token and no login, so the
     * signature is the only thing standing between the gateway and anyone
     * who knows the URL. Each way of getting it wrong is refused.
     */

    private function startPayment(): void
    {
        $this->fakePayMongo('paid');
        $this->actingAs($this->tourist)
            ->post(route('tourist.payments.start', $this->booking), ['provider' => 'paymongo']);
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $this->startPayment();

        $this->post(route('payments.webhook', 'paymongo'), ['data' => ['id' => 'cs_pretend_123']])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }

    public function test_a_webhook_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->startPayment();

        $this->payMongoWebhook(['data' => ['id' => 'cs_pretend_123']], null, 'whsk_attacker')
            ->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }

    public function test_whitespace_around_the_paymongo_secrets_is_ignored(): void
    {
        config([
            'services.paymongo.secret' => ' sk_test_pretend ',
            'services.paymongo.webhook_secret' => ' '.self::WEBHOOK_SECRET.' ',
        ]);

        $this->startPayment();

        $payload = json_encode(['data' => ['id' => 'cs_pretend_123']]);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        $this->call(
            'POST',
            route('payments.webhook', 'paymongo'),
            [], [], [],
            [
                'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=notusedintestmode",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        )->assertOk();

        $this->assertSame(PaymentStatus::Paid, Payment::sole()->status);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $this->startPayment();

        // Signed one payload, sent another.
        $this->payMongoWebhook(
            ['data' => ['id' => 'cs_pretend_123']],
            null,
            null,
            json_encode(['data' => ['id' => 'cs_somebody_elses']]),
        )->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }

    public function test_a_replayed_webhook_is_refused_once_it_is_stale(): void
    {
        $this->startPayment();

        // Correctly signed, but captured an hour ago.
        $this->payMongoWebhook(
            ['data' => ['id' => 'cs_pretend_123']],
            now()->subHour()->getTimestamp(),
        )->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }

    public function test_nothing_is_accepted_when_no_webhook_secret_is_configured(): void
    {
        config(['services.paymongo.webhook_secret' => null]);
        $this->startPayment();

        // Fails closed: an unverifiable webhook is worth less than none.
        $this->payMongoWebhook(['data' => ['id' => 'cs_pretend_123']])->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }

    public function test_a_live_signature_is_not_accepted_by_a_test_key(): void
    {
        $this->startPayment();

        $payload = json_encode(['data' => ['id' => 'cs_pretend_123']]);
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        // Right signature, but offered only as the live one while a test key
        // is in use, so the test slot holds nothing valid.
        $this->call(
            'POST',
            route('payments.webhook', 'paymongo'),
            [], [], [],
            [
                'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te=wrong,li={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        )->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }
}
