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
