<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * PayPal, for guests paying from abroad.
 *
 * Uses PayPal's Orders v2: we create an order, send the guest to PayPal to
 * approve it, then capture it when they return. An order that is approved but
 * never captured takes no money, so the capture on return is the step that
 * actually charges.
 *
 * Which PayPal this talks to is set by services.paypal.mode: "sandbox" for
 * testing with fake money, "live" for real. Nothing else changes.
 */
class PayPalGateway implements PaymentGateway
{
    public function start(Payment $payment, string $returnUrl, string $cancelUrl): array
    {
        $response = $this->request()->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $payment->payment_reference,
                'description' => 'Reservation '.$payment->booking->booking_reference,
                'amount' => [
                    'currency_code' => $payment->currency,
                    // PayPal wants a decimal string, not centavos.
                    'value' => number_format((float) $payment->amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name' => config('visitdasol.name'),
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);

        if ($response->failed()) {
            throw PaymentException::refused('PayPal');
        }

        $id = $response->json('id');
        $approve = null;

        foreach ((array) $response->json('links', []) as $link) {
            if (($link['rel'] ?? null) === 'approve' && isset($link['href'])) {
                $approve = $link['href'];
                break;
            }
        }

        if (! is_string($id) || ! is_string($approve)) {
            throw PaymentException::refused('PayPal');
        }

        return ['reference' => $id, 'checkout_url' => $approve, 'payload' => (array) $response->json()];
    }

    public function settle(Payment $payment): array
    {
        $order = $this->base().'/v2/checkout/orders/'.$payment->provider_reference;

        // Capturing an order that is already captured answers 422, which is not
        // a failure: it means the money is already taken. Read the order back
        // in that case rather than telling the guest something went wrong.
        $response = $this->request()->post($order.'/capture', new \stdClass);

        if ($response->status() === 422 || $response->failed()) {
            $response = $this->request()->get($order);
        }

        if ($response->failed()) {
            throw PaymentException::unreachable('PayPal');
        }

        $status = $response->json('status');

        return [
            'status' => $status === 'COMPLETED' ? PaymentStatus::Paid : PaymentStatus::Pending,
            'method' => 'paypal',
            'payload' => (array) $response->json(),
        ];
    }

    /**
     * PayPal does not sign with a shared secret. It signs with a certificate
     * and asks you to check the signature through its own API, which is the
     * method PayPal itself recommends over verifying the chain locally.
     *
     * Note that PAYPAL-CERT-URL comes from the caller, so it must never be
     * fetched here: it is handed straight to PayPal, which only accepts a
     * certificate of its own. Downloading it ourselves would let anyone point
     * us at a certificate they control.
     */
    public function verifyWebhook(Request $request): bool
    {
        $webhookId = (string) config('services.paypal.webhook_id');

        if ($webhookId === '') {
            return false;
        }

        $headers = [
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
        ];

        foreach ($headers as $value) {
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        try {
            $response = $this->request()->post(
                $this->base().'/v1/notifications/verify-webhook-signature',
                $headers + [
                    'webhook_id' => $webhookId,
                    'webhook_event' => $request->json()->all(),
                ]
            );
        } catch (Throwable) {
            // Unreachable means unproven, and unproven means refused.
            return false;
        }

        return $response->successful()
            && $response->json('verification_status') === 'SUCCESS';
    }

    /** An access token, asked for fresh each time and never stored. */
    private function token(): string
    {
        $id = config('services.paypal.client_id');
        $secret = config('services.paypal.secret');

        if (blank($id) || blank($secret)) {
            throw PaymentException::notConfigured('PayPal');
        }

        $response = Http::withBasicAuth($id, $secret)
            ->asForm()
            ->timeout(20)
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token)) {
            throw PaymentException::unreachable('PayPal');
        }

        return $token;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, fn (Throwable $e): bool => $e instanceof ConnectionException, false);
    }

    private function base(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
