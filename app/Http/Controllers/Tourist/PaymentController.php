<?php

namespace App\Http\Controllers\Tourist;

use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paying for a confirmed reservation.
 *
 * The guest never types payment details here. They are sent to the gateway's
 * own page and come back with a reference, which is checked against the
 * gateway before anything is marked paid. Coming back is not proof of
 * payment; only the gateway's answer is.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Opens a checkout and sends the guest to the gateway. */
    public function start(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $provider = PaymentProvider::tryFrom((string) $request->input('provider'));

        if ($provider === null) {
            return back()->with('error', 'Choose how you would like to pay.');
        }

        try {
            $payment = $this->payments->start(
                $booking,
                $provider,
                route('tourist.payments.return', $booking),
                route('tourist.payments.cancel', $booking),
            );
        } catch (Throwable $e) {
            return back()->with('error', $this->payments->report($e, $booking));
        }

        return redirect()->away($payment->checkout_url);
    }

    /**
     * Where the gateway sends the guest afterwards.
     *
     * The reservation is only credited if the gateway itself says the money
     * arrived, so opening this URL by hand achieves nothing.
     */
    /**
     * Open a card payment for the form on our own page.
     *
     * Answers JSON, because the page asks with fetch() and then talks to
     * PayMongo itself. What goes back is an intent id and a client key —
     * never the secret key, which would let whoever held it read every
     * payment this site has ever taken.
     *
     * The billing email comes from the booking rather than from the form:
     * PayMongo requires one, the guest already gave it, and a field asking
     * again is a field to mistype.
     */
    public function startCard(Booking $booking): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $booking);

        try {
            ['payment' => $payment, 'client_key' => $clientKey] = $this->payments->startCard($booking);
        } catch (Throwable $e) {
            return response()->json(['message' => $this->payments->report($e, $booking)], 422);
        }

        $booking->loadMissing('tourist');

        return response()->json([
            'public_key' => config('services.paymongo.public'),
            'intent_id' => $payment->provider_reference,
            'client_key' => $clientKey,
            'amount' => (string) $payment->amount,
            'billing' => [
                'name' => $booking->contact_name,
                'email' => $booking->contact_email ?: $booking->tourist?->email,
            ],
            // Where the bank sends the cardholder back after 3-D Secure.
            'return_url' => route('tourist.payments.card.return', $booking),
        ]);
    }

    /**
     * The cardholder is back — from the bank's 3-D Secure page, or from
     * the form itself when no authentication was needed.
     *
     * Nothing in the request is believed. The payment is read back from
     * PayMongo with the secret key, and that answer alone decides whether
     * this reservation has been paid for.
     */
    public function cardReturn(Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $payment = $booking->payments()
            ->open()
            ->where('method', 'card')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return redirect()->route('tourist.bookings.show', $booking);
        }

        try {
            $payment = $this->payments->settleCard($payment);
        } catch (Throwable $e) {
            return redirect()->route('tourist.bookings.show', $booking)
                ->with('error', $this->payments->report($e, $booking));
        }

        if ($payment->isSettled()) {
            return redirect()->route('tourist.bookings.show', $booking)
                ->with('success', 'Thank you. Your card payment went through.');
        }

        return redirect()->route('tourist.bookings.show', $booking)
            ->with('warning', $payment->failure_reason
                ?: 'That payment has not completed. You can try again, or pay at the resort.');
    }

    public function return(Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $payment = $booking->payments()->open()->first();

        if ($payment === null) {
            return redirect()->route('tourist.bookings.show', $booking);
        }

        try {
            $payment = $this->payments->settle($payment);
        } catch (Throwable $e) {
            return redirect()->route('tourist.bookings.show', $booking)
                ->with('warning', $this->payments->report($e, $booking));
        }

        if ($payment->isSettled()) {
            return redirect()->route('tourist.bookings.show', $booking)
                ->with('success', "Payment received. Reference {$payment->payment_reference}.");
        }

        return redirect()->route('tourist.bookings.show', $booking)
            ->with('warning', 'We have not had confirmation of that payment yet. If it was taken, it will show here shortly.');
    }

    /** The guest backed out on the gateway's page. */
    public function cancel(Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        $payment = $booking->payments()->open()->first();

        if ($payment !== null) {
            $this->payments->abandon($payment);
        }

        return redirect()->route('tourist.bookings.show', $booking)
            ->with('info', 'Payment cancelled. Nothing was charged, and you can pay at any time before you arrive.');
    }

    /**
     * The gateway telling us about a payment out of band.
     *
     * A guest who closes the tab mid-payment never loads the return URL, so
     * without this their money would arrive with nothing recorded. Both routes
     * end in the same settle(), which only credits a reservation once.
     *
     * No session, no CSRF token, and no authentication: the caller is a server,
     * not a person. The gateway's signature is therefore the only thing that
     * separates it from anyone who knows this URL, so an unsigned or wrongly
     * signed request is refused before it can reach a payment.
     *
     * It fails closed. With no webhook secret configured nothing is accepted,
     * because an unverifiable webhook is worth less than no webhook at all.
     */
    public function webhook(Request $request, string $provider): \Illuminate\Http\Response
    {
        $gateway = PaymentProvider::tryFrom($provider);

        if ($gateway === null) {
            return response('', 404);
        }

        if (! $this->payments->verifyWebhook($gateway, $request)) {
            /*
             * 403, not 200. A real gateway retries and raises this in its own
             * dashboard, which is how a wrong secret gets noticed; answering
             * 200 would hide a broken configuration for as long as it lasted.
             */
            Log::warning('Refused an unverified '.$gateway->value.' webhook', [
                'ip' => $request->ip(),
            ]);

            return response('', 403);
        }

        $reference = $this->referenceFrom($request);

        if ($reference !== null) {
            $payment = Payment::query()
                ->where('provider', $gateway->value)
                ->where('provider_reference', $reference)
                ->first();

            if ($payment !== null) {
                try {
                    $this->payments->settle($payment);
                } catch (Throwable) {
                    // Answer 200 anyway: a gateway that gets an error retries
                    // for hours. The payment stays open and is picked up when
                    // the guest next opens the reservation.
                }
            }
        }

        return response('', 200);
    }

    /** The gateway's own id for the checkout, wherever it sits in the body. */
    private function referenceFrom(Request $request): ?string
    {
        foreach ([
            'data.attributes.data.id',   // PayMongo
            'data.id',
            'resource.id',               // PayPal
            'id',
        ] as $path) {
            $value = $request->input($path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
