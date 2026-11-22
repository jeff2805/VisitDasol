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
 * Paying by card on our own page.
 *
 * THE PROPERTY WORTH MORE THAN ANY OTHER HERE: no route on this site
 * accepts a card number. The form posts nothing but a CSRF token; the
 * number goes from the browser to PayMongo and the server is told a token.
 * Several of these tests exist only to keep that true.
 *
 * The second property: the browser's word is never evidence. The page that
 * attached the card knows how it went, and a page can be made to say
 * anything, so the server asks PayMongo with its own key.
 */
class CardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        config([
            'services.paymongo.secret' => 'sk_test_pretend',
            'services.paymongo.public' => 'pk_test_pretend',
            'services.paymongo.methods' => ['card'],
        ]);

        $this->tourist = User::factory()->create(['email' => 'guest@example.test']);

        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create(['capacity' => 4, 'price_per_night' => 1000]);

        $this->booking = Booking::factory()
            ->for($resort)->for($room)->for($this->tourist, 'tourist')
            ->forStay('2030-07-01', '2030-07-03')
            ->confirmed()
            ->create(['total_amount' => '2000.00', 'contact_email' => 'guest@example.test']);
    }

    private function intentResponse(string $status = 'awaiting_payment_method'): array
    {
        return ['data' => ['id' => 'pi_test123', 'attributes' => [
            'status' => $status,
            'client_key' => 'pi_test123_client_abc',
            'amount' => 200000,
        ]]];
    }

    /* Opening a card payment --------------------------------------------- */

    public function test_the_page_offers_the_card_form_once_the_resort_has_confirmed(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertSee('Pay by card here')
            ->assertSee('Card holder name')
            ->assertSee('card-payment.js', false);
    }

    public function test_a_pending_reservation_has_no_card_form(): void
    {
        $this->booking->forceFill(['status' => BookingStatus::Pending])->save();

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Card holder name');
    }

    public function test_without_a_publishable_key_there_is_no_card_form(): void
    {
        config(['services.paymongo.public' => null]);

        $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->assertDontSee('Card holder name');
    }

    public function test_starting_a_card_payment_returns_what_the_browser_needs(): void
    {
        Http::fake(['api.paymongo.com/v1/payment_intents' => Http::response($this->intentResponse(), 200)]);

        $response = $this->actingAs($this->tourist)
            ->postJson(route('tourist.payments.card.start', $this->booking))
            ->assertOk();

        $response->assertJsonPath('public_key', 'pk_test_pretend');
        $response->assertJsonPath('intent_id', 'pi_test123');
        $response->assertJsonPath('client_key', 'pi_test123_client_abc');
        $response->assertJsonPath('billing.email', 'guest@example.test');
        $response->assertJsonPath('amount', '2000.00');
    }

    /**
     * The secret key opens payments and reads every one this site has
     * taken. It has no business in a page.
     */
    public function test_the_secret_key_never_reaches_the_browser(): void
    {
        Http::fake(['api.paymongo.com/v1/payment_intents' => Http::response($this->intentResponse(), 200)]);

        $json = $this->actingAs($this->tourist)
            ->postJson(route('tourist.payments.card.start', $this->booking))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('sk_test_pretend', $json);

        $page = $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('sk_test_pretend', $page);
    }

    /**
     * The form has nowhere to post a card number to, and that is the point.
     */
    public function test_no_route_on_this_site_accepts_a_card_number(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response($this->intentResponse(), 200)]);

        $this->actingAs($this->tourist)->postJson(route('tourist.payments.card.start', $this->booking), [
            'card_number' => '4343434343434345',
            'card_cvc' => '123',
        ])->assertOk();

        // Whatever was posted, none of it was kept.
        $payment = Payment::query()->firstOrFail();
        $stored = json_encode([$payment->toArray(), $payment->payload]);

        $this->assertStringNotContainsString('4343434343434345', $stored);
        $this->assertStringNotContainsString('123456', $stored);
    }

    public function test_a_second_attempt_reuses_the_open_payment(): void
    {
        Http::fake(['api.paymongo.com/v1/payment_intents' => Http::response($this->intentResponse(), 200)]);

        $this->actingAs($this->tourist)->postJson(route('tourist.payments.card.start', $this->booking))->assertOk();
        $this->actingAs($this->tourist)->postJson(route('tourist.payments.card.start', $this->booking))->assertOk();

        $this->assertSame(1, Payment::query()->count(), 'A retry must not leave a trail of payments.');
    }

    public function test_a_stranger_cannot_open_a_payment_on_somebody_elses_booking(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('tourist.payments.card.start', $this->booking))
            ->assertForbidden();
    }

    /* Coming back -------------------------------------------------------- */

    public function test_a_succeeded_intent_marks_the_payment_paid(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::sequence()
                ->push($this->intentResponse(), 200)
                ->push($this->intentResponse('succeeded'), 200),
            'api.paymongo.com/v1/payment_intents/*' => Http::response($this->intentResponse('succeeded'), 200),
        ]);

        $this->actingAs($this->tourist)->postJson(route('tourist.payments.card.start', $this->booking));

        $this->actingAs($this->tourist)
            ->get(route('tourist.payments.card.return', $this->booking))
            ->assertRedirect(route('tourist.bookings.show', $this->booking));

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('card', $payment->method);
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * The whole reason the server asks PayMongo rather than the page.
     */
    public function test_a_payment_that_did_not_succeed_is_not_marked_paid(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response($this->intentResponse(), 200),
            'api.paymongo.com/v1/payment_intents/*' => Http::response($this->intentResponse('awaiting_next_action'), 200),
        ]);

        $this->actingAs($this->tourist)->postJson(route('tourist.payments.card.start', $this->booking));
        $this->actingAs($this->tourist)->get(route('tourist.payments.card.return', $this->booking));

        $this->assertSame(PaymentStatus::Pending, Payment::query()->firstOrFail()->status);
    }

    public function test_returning_with_no_payment_open_is_harmless(): void
    {
        $this->actingAs($this->tourist)
            ->get(route('tourist.payments.card.return', $this->booking))
            ->assertRedirect(route('tourist.bookings.show', $this->booking));
    }

    /* Refusals ------------------------------------------------------------ */

    public function test_a_pending_reservation_cannot_be_charged(): void
    {
        $this->booking->forceFill(['status' => BookingStatus::Pending])->save();

        $this->actingAs($this->tourist)
            ->postJson(route('tourist.payments.card.start', $this->booking))
            ->assertStatus(422);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_reservation_with_nothing_owing_cannot_be_charged(): void
    {
        $this->booking->payments()->create([
            'payment_reference' => 'VDP-PAID',
            'provider' => 'paymongo',
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->actingAs($this->tourist)
            ->postJson(route('tourist.payments.card.start', $this->booking))
            ->assertStatus(422);
    }

    /* The policy that lets any of this reach PayMongo --------------------- */

    public function test_the_page_lets_the_browser_reach_paymongo(): void
    {
        $policy = $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://api.paymongo.com', (string) $policy);
    }

    public function test_without_a_card_form_paymongos_api_is_not_allowed(): void
    {
        config(['services.paymongo.public' => null]);

        $policy = $this->actingAs($this->tourist)
            ->get(route('tourist.bookings.show', $this->booking))
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('api.paymongo.com', (string) $policy);
    }
}
