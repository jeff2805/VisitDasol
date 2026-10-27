<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Online payment for a reservation. The only place a payment is ever created
 * or marked paid.
 *
 * Two rules decide everything here:
 *
 *   1. Only a confirmed reservation may be paid. A request the resort has not
 *      accepted yet can still be declined, and taking money that would then
 *      have to be sent back is worse than waiting.
 *   2. The amount is read from the booking, never from the request. A guest
 *      cannot choose what to pay by editing the page.
 */
class PaymentService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** Whether this reservation is at a point where paying makes sense. */
    public function isPayable(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed && $this->dueNow($booking) > 0;
    }

    /** What is still owed on the whole stay, in pesos. */
    public function outstanding(Booking $booking): float
    {
        $paid = (float) $booking->payments()->settled()->sum('amount');

        return max(0, round((float) $booking->total_amount - $paid, 2));
    }

    /**
     * What has to be paid BEFORE arrival, in pesos, less whatever has
     * already been settled.
     *
     * Different from outstanding() wherever a resort takes a deposit: a
     * guest who has paid their 30% owes nothing more until they arrive,
     * and a "Pay now" button for the rest would be asking for money the
     * resort has not asked for.
     *
     * A booking made before payment policies existed has no snapshot and
     * falls back to the whole amount, which is how it was quoted.
     */
    public function dueNow(Booking $booking): float
    {
        $required = $booking->amount_due_now === null
            ? (float) $booking->total_amount
            : min((float) $booking->amount_due_now, (float) $booking->total_amount);

        $paid = (float) $booking->payments()->settled()->sum('amount');

        return max(0, round($required - $paid, 2));
    }

    public function isSettled(Booking $booking): bool
    {
        return $booking->payments()->settled()->exists();
    }

    /**
     * Open a checkout and return the payment to send the guest to.
     *
     * A reservation that already has an open attempt with the same gateway
     * reuses it, so tapping "Pay" twice does not leave a trail of abandoned
     * checkouts.
     *
     * @throws PaymentException
     */
    public function start(Booking $booking, PaymentProvider $provider, string $returnUrl, string $cancelUrl): Payment
    {
        if (! $provider->isConfigured()) {
            throw PaymentException::notConfigured($provider->label());
        }

        /*
         * What the resort has actually asked for before arrival, not the
         * whole bill. Charging a guest the full amount when their resort
         * takes a 30% deposit would be taking money nobody asked for.
         */
        $owed = $this->dueNow($booking);

        if ($booking->status !== BookingStatus::Confirmed || $owed <= 0) {
            throw PaymentException::nothingToPay();
        }

        $payment = DB::transaction(function () use ($booking, $provider, $owed): Payment {
            $existing = $booking->payments()
                ->open()
                ->where('provider', $provider->value)
                ->lockForUpdate()
                ->first();

            if ($existing && (float) $existing->amount === $owed) {
                return $existing;
            }

            return $booking->payments()->create([
                'payment_reference' => $this->reference(),
                'provider' => $provider,
                'amount' => number_format($owed, 2, '.', ''),
                'currency' => 'PHP',
                'status' => PaymentStatus::Pending,
            ]);
        });

        $opened = $this->gateway($provider)->start($payment->load('booking.resort'), $returnUrl, $cancelUrl);

        $payment->forceFill([
            'provider_reference' => $opened['reference'],
            'checkout_url' => $opened['checkout_url'],
            'payload' => $opened['payload'],
        ])->save();

        return $payment;
    }

    /**
     * Ask the gateway what became of a payment and record the answer.
     *
     * Safe to call as often as you like: a payment that is already settled is
     * returned untouched, so the guest returning, refreshing, and a webhook
     * arriving all land on the same result and the booking is only ever
     * credited once.
     *
     * @throws PaymentException
     */
    public function settle(Payment $payment): Payment
    {
        if ($payment->provider === PaymentProvider::ManualGcash || $payment->isSettled() || blank($payment->provider_reference)) {
            return $payment;
        }

        $answer = $this->gateway($payment->provider)->settle($payment->load('booking'));

        if ($answer['status'] !== PaymentStatus::Paid) {
            // Still unfinished. Left open on purpose, so a guest who stepped
            // away can come back to the same checkout.
            $payment->forceFill(['payload' => $answer['payload']])->save();

            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'method' => $answer['method'],
            'paid_at' => now(),
            'payload' => $answer['payload'],
        ])->save();

        $this->logger->log(
            'payment.paid',
            $payment->booking,
            "Paid {$payment->payment_reference} for {$payment->booking->booking_reference} through {$payment->provider->value}",
            ['amount' => (string) $payment->amount, 'method' => $answer['method']],
            $payment->booking->tourist,
        );

        return $payment;
    }

    /**
     * Is this webhook really from the gateway it claims to be?
     *
     * Kept here so the controller never has to know how a gateway signs, and
     * so a gateway that cannot be checked is refused rather than trusted.
     */
    public function verifyWebhook(PaymentProvider $provider, Request $request): bool
    {
        try {
            return $this->gateway($provider)->verifyWebhook($request);
        } catch (Throwable $e) {
            Log::warning('Could not verify a '.$provider->value.' webhook', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /** Marks an attempt the guest walked away from, so it stops being offered. */
    public function abandon(Payment $payment): void
    {
        if ($payment->status->isOpen()) {
            $payment->forceFill(['status' => PaymentStatus::Cancelled])->save();
        }
    }

    /**
     * Turns a gateway failure into something a guest can read, and puts the
     * real reason in the log where the office can find it.
     */
    public function report(\Throwable $e, Booking $booking): string
    {
        if ($e instanceof PaymentException) {
            return $e->getMessage();
        }

        Log::error('Payment failed for '.$booking->booking_reference, [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return 'The payment could not be started just now. Please try again, or contact the resort to arrange payment.';
    }

    private function gateway(PaymentProvider $provider): PaymentGateway
    {
        return match ($provider) {
            PaymentProvider::PayMongo => app(PayMongoGateway::class),
            PaymentProvider::PayPal => app(PayPalGateway::class),
            PaymentProvider::ManualGcash => throw new \LogicException('Manual GCash has no gateway.'),
        };
    }

    /** Short, unmistakable, and not guessable from the one before it. */
    private function reference(): string
    {
        do {
            $reference = 'VDP-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (Payment::query()->where('payment_reference', $reference)->exists());

        return $reference;
    }
}
