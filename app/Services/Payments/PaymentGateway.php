<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * What every payment gateway must be able to do.
 *
 * Both gateways work the same way and it is the safest way there is: the
 * guest is sent to the gateway's own page to pay, and comes back with
 * nothing but a reference. No card number, wallet PIN, or account name ever
 * reaches this application, so there is nothing here for an attacker to take.
 */
interface PaymentGateway
{
    /**
     * Open a checkout with the gateway and return where to send the guest.
     *
     * @return array{reference: string, checkout_url: string, payload: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function start(Payment $payment, string $returnUrl, string $cancelUrl): array;

    /**
     * Ask the gateway what became of this payment.
     *
     * Called when the guest returns and again by the webhook, because the
     * browser coming back is not proof of anything: only the gateway's own
     * answer is. Both routes end here, so a guest who closes the tab mid-
     * payment is still recorded correctly.
     *
     * @return array{status: \App\Enums\PaymentStatus, method: ?string, payload: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function settle(Payment $payment): array;

    /**
     * Is this webhook really from the gateway?
     *
     * The webhook endpoint has no session and no CSRF token, so the signature
     * is the only thing that separates the gateway from anyone who knows the
     * URL. Each gateway signs differently, so each one checks its own.
     *
     * Must fail closed: when the secret is missing, the header is absent, or
     * anything at all is off, the answer is false.
     */
    public function verifyWebhook(Request $request): bool;
}
