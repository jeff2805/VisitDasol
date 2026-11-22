<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayMongo, which is how a guest pays with GCash, Maya, GrabPay, or a card.
 *
 * Uses PayMongo's Checkout Sessions: we open a session, send the guest to
 * PayMongo's page, and read the answer back afterwards. The guest picks the
 * wallet or card there, so this application never sees it.
 *
 * Amounts go to PayMongo in centavos as whole numbers, which is why
 * Payment::centavos() exists: ₱8,025.00 is sent as 802500.
 */
class PayMongoGateway implements PaymentGateway
{
    private const BASE = 'https://api.paymongo.com/v1';

    public function start(Payment $payment, string $returnUrl, string $cancelUrl): array
    {
        $booking = $payment->booking;

        $response = $this->request()->post(self::BASE.'/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => 'Reservation '.$booking->booking_reference,
                        'quantity' => 1,
                        'currency' => $payment->currency,
                        'amount' => $payment->centavos(),
                    ]],
                    'payment_method_types' => config('services.paymongo.methods'),
                    'description' => $this->description($payment),
                    'reference_number' => $payment->payment_reference,
                    'success_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw PaymentException::refused('PayMongo');
        }

        $id = $response->json('data.id');
        $url = $response->json('data.attributes.checkout_url');

        if (! is_string($id) || ! is_string($url)) {
            throw PaymentException::refused('PayMongo');
        }

        return ['reference' => $id, 'checkout_url' => $url, 'payload' => (array) $response->json()];
    }

    /**
     * Open a payment the guest will pay for with a card typed on OUR page.
     *
     * A Payment Intent rather than a Checkout Session, because the two
     * answer different questions. A session says "send them to PayMongo and
     * they will sort it out"; an intent says "here is an amount, something
     * will be attached to it shortly" — and that something is a payment
     * method the BROWSER makes, out of a card number this server never
     * sees and could not log if it tried.
     *
     * What comes back is the intent's id and its client key. The client key
     * is safe in the page: it can attach a payment method to this one
     * intent and do nothing else — it cannot read a customer, list
     * payments, or touch another booking.
     *
     * @return array{reference: string, client_key: string, payload: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function startCardIntent(Payment $payment): array
    {
        $response = $this->request()->post(self::BASE.'/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $payment->centavos(),
                    'currency' => $payment->currency,
                    'description' => $this->description($payment),
                    'statement_descriptor' => mb_substr(config('visitdasol.name'), 0, 22),
                    'payment_method_allowed' => ['card'],
                    /*
                     * Automatic: the card is charged the moment it clears,
                     * with no second "capture" step. A resort expecting the
                     * money to arrive has nothing further to press, and a
                     * guest is never left with a hold that quietly expires.
                     */
                    'capture_type' => 'automatic',
                ],
            ],
        ]);

        if ($response->failed()) {
            throw PaymentException::refused('PayMongo');
        }

        $id = $response->json('data.id');
        $clientKey = $response->json('data.attributes.client_key');

        if (! is_string($id) || ! is_string($clientKey)) {
            throw PaymentException::refused('PayMongo');
        }

        return [
            'reference' => $id,
            'client_key' => $clientKey,
            'payload' => (array) $response->json(),
        ];
    }

    /**
     * Charge a card, now.
     *
     * THE MONEY IS TAKEN HERE AND CANNOT BE PUT BACK BY THIS SITE.
     *
     * This was written as a HOLD first — reserve the amount, take it only
     * once the resort accepts, release it if they decline — which is how a
     * booking that can still be refused ought to be paid for. PayMongo
     * refused: "Manual capture is not supported for your card provider."
     * Refunds are not wired up either. So a guest whose reservation is
     * declined after paying has no way back through this system, and the
     * office has to settle it by hand.
     *
     * That is a decision the tourism office made knowing the consequence.
     * What this code owes them in return is that the guest is told the
     * same thing before they type a card — see the booking form.
     *
     * capture_type automatic: there is no second step to forget.
     *
     * @return array{reference: string, status: PaymentStatus, redirect: ?string, failure: ?string, payload: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function chargeCard(Payment $payment, string $paymentMethodId, string $returnUrl): array
    {
        $intent = $this->request()->post(self::BASE.'/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $payment->centavos(),
                    'currency' => $payment->currency,
                    'description' => $this->description($payment),
                    'statement_descriptor' => mb_substr((string) config('visitdasol.name'), 0, 22),
                    'payment_method_allowed' => ['card'],
                    'capture_type' => 'automatic',
                ],
            ],
        ]);

        if ($intent->failed()) {
            throw PaymentException::refused('PayMongo');
        }

        $id = (string) $intent->json('data.id');
        $clientKey = (string) $intent->json('data.attributes.client_key');

        $attached = $this->request()->post(
            self::BASE.'/payment_intents/'.$id.'/attach?client_key='.urlencode($clientKey),
            ['data' => ['attributes' => [
                'payment_method' => $paymentMethodId,
                'return_url' => $returnUrl,
            ]]]
        );

        if ($attached->failed()) {
            return [
                'reference' => $id,
                'status' => PaymentStatus::Failed,
                'redirect' => null,
                'failure' => (string) ($attached->json('errors.0.detail') ?? 'The card was not accepted.'),
                'payload' => (array) $attached->json(),
            ];
        }

        return $this->readIntent($id, (array) $attached->json());
    }

    /*
     * capture() and release() were written here and then taken out.
     *
     * They were the two halves of paying by hold: capture when the resort
     * accepts, release when it declines. PayMongo answers
     * "Manual capture is not supported for your card provider", so
     * neither could ever have run — and a method that cannot work is worse
     * than no method, because the next person to read this file would
     * build on it.
     *
     * If that provider restriction is ever lifted, this is the shape the
     * payment flow should go back to: charging for a reservation that can
     * still be declined is what makes a refund necessary in the first
     * place.
     */

    /**
     * One intent's state, read the same way wherever it came from.
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: string, status: PaymentStatus, redirect: ?string, failure: ?string, payload: array<string, mixed>}
     */
    private function readIntent(string $id, array $payload): array
    {
        $attributes = (array) data_get($payload, 'data.attributes', []);
        $state = (string) ($attributes['status'] ?? '');
        $redirect = data_get($attributes, 'next_action.redirect.url');
        $failure = data_get($attributes, 'last_payment_error.detail');

        return [
            'reference' => $id,
            /*
             * awaiting_capture is a hold that worked: the bank has
             * reserved the money and is waiting to be told to take it.
             * It is NOT paid, and must never be recorded as such — the
             * guest has not been charged a peso yet.
             */
            'status' => match ($state) {
                'succeeded' => PaymentStatus::Paid,
                'awaiting_capture' => PaymentStatus::Pending,
                'awaiting_next_action', 'processing', 'awaiting_payment_method' => PaymentStatus::Pending,
                'cancelled' => PaymentStatus::Cancelled,
                default => PaymentStatus::Pending,
            },
            'redirect' => is_string($redirect) ? $redirect : null,
            'failure' => is_string($failure) ? $failure : null,
            'payload' => $payload,
        ];
    }

    /**
     * What became of a card payment, asked of PayMongo and nobody else.
     *
     * THE BROWSER'S WORD IS NOT EVIDENCE. The page that attached the card
     * knows whether it worked, and a page can be made to say anything; this
     * reads the intent back with the secret key, which only this server
     * holds.
     *
     * @return array{status: PaymentStatus, method: ?string, failure: ?string, payload: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function settleCardIntent(Payment $payment): array
    {
        $response = $this->request()->get(self::BASE.'/payment_intents/'.$payment->provider_reference);

        if ($response->failed()) {
            throw PaymentException::unreachable('PayMongo');
        }

        $attributes = (array) $response->json('data.attributes', []);
        $state = (string) ($attributes['status'] ?? '');

        /*
         * PayMongo's own words, mapped to ours.
         *
         * awaiting_next_action is the 3-D Secure step: the bank wants the
         * cardholder to confirm, and until they do the payment is neither
         * finished nor failed. It stays open, because a guest who stepped
         * away mid-authentication must find the payment still waiting.
         */
        $status = match ($state) {
            'succeeded' => PaymentStatus::Paid,
            'awaiting_payment_method' => PaymentStatus::Pending,
            'awaiting_next_action', 'processing' => PaymentStatus::Pending,
            default => PaymentStatus::Pending,
        };

        $last = (array) ($attributes['last_payment_error'] ?? []);

        return [
            'status' => $status,
            'method' => 'card',
            'failure' => is_string($last['detail'] ?? null) ? $last['detail'] : null,
            'payload' => (array) $response->json(),
        ];
    }

    public function settle(Payment $payment): array
    {
        $response = $this->request()->get(self::BASE.'/checkout_sessions/'.$payment->provider_reference);

        if ($response->failed()) {
            throw PaymentException::unreachable('PayMongo');
        }

        /*
         * A session holds a list of payments. It is settled the moment any one
         * of them is paid; anything else means the guest has not finished, so
         * the attempt is left open rather than marked failed. A guest who wanders
         * off and comes back an hour later must not find a dead payment.
         */
        $payments = (array) $response->json('data.attributes.payments', []);
        $status = PaymentStatus::Pending;
        $method = null;

        foreach ($payments as $entry) {
            $attributes = (array) ($entry['attributes'] ?? []);

            if (($attributes['status'] ?? null) === 'paid') {
                $status = PaymentStatus::Paid;
                $method = $attributes['source']['type'] ?? ($attributes['payment_method_used'] ?? null);
                break;
            }
        }

        return [
            'status' => $status,
            'method' => is_string($method) ? $method : null,
            'payload' => (array) $response->json(),
        ];
    }

    /**
     * PayMongo signs a webhook with a header shaped like:
     *
     *     Paymongo-Signature: t=1496734173,te=<test sig>,li=<live sig>
     *
     * The signature is HMAC-SHA256 over "<t>.<raw body>" keyed with the
     * webhook secret (whsk_...), which is a different value from the API
     * secret key. Which of te/li to read follows the API key in use, so a
     * test key never accepts a live signature or the other way round.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = trim((string) config('services.paymongo.webhook_secret'));
        $header = $request->header('Paymongo-Signature');

        if ($secret === '' || ! is_string($header) || $header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) !== 2) {
                continue;
            }

            $key = trim($pair[0]);
            $value = trim($pair[1]);

            if ($key !== '' && $value !== '') {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts[$this->isLive() ? 'li' : 'te'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return false;
        }

        // A captured request must not work forever.
        $tolerance = (int) config('services.webhooks.tolerance', 300);

        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false;
        }

        // The RAW body, not the parsed array: re-encoding would change the
        // bytes and the signature would never match.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        // Constant time, so a wrong guess cannot be narrowed down by timing.
        return hash_equals($expected, $signature);
    }

    /** A PayMongo key names its own mode: sk_live_... or sk_test_... */
    private function isLive(): bool
    {
        $secret = strtolower(trim((string) config('services.paymongo.secret')));

        return str_starts_with($secret, 'sk_live_');
    }

    /**
     * PayMongo authenticates with the secret key as the HTTP Basic username
     * and no password.
     */
    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $secret = trim((string) config('services.paymongo.secret'));

        if (blank($secret)) {
            throw PaymentException::notConfigured('PayMongo');
        }

        return Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, fn (Throwable $e): bool => $e instanceof ConnectionException, false);
    }

    private function description(Payment $payment): string
    {
        $booking = $payment->booking;

        return trim(($booking->resort?->name ?? 'Resort').', '
            .$booking->check_in_date->format('M j').' to '.$booking->check_out_date->format('M j, Y'));
    }
}
