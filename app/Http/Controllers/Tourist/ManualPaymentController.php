<?php

namespace App\Http\Controllers\Tourist;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Services\Payments\ManualPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManualPaymentController extends Controller
{
    public function __construct(
        private readonly ManualPaymentService $manualPayments
    ) {}

    public function start(Booking $booking): RedirectResponse
    {
        $this->authorize('view', $booking);

        try {
            $this->manualPayments->start($booking);
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Unable to start your GCash payment. Please try again.'
            );
        }

        return redirect()
            ->route('tourist.manual-payments.show', $booking)
            ->with(
                'success',
                'Your GCash payment is ready. Send the money to the resort account and upload the proof below.'
            );
    }

    public function show(Booking $booking): View
    {
        $this->authorize('view', $booking);

        $payment = $booking->payments()
            ->where('provider', 'manual_gcash')
            ->latest()
            ->first();

        if ($payment === null) {
            abort(404);
        }

        $account = $booking->resort
            ->manualAccount()
            ->where('active', true)
            ->first();

        $proof = $payment instanceof Payment
            ? $payment->proofs()->first()
            : null;

        // Built by the model, so a remote public disk (Supabase Storage)
        // and a file that has gone are both handled in one place.
        $qrUrl = $account?->qr_url;

        return view('manual-gcash.pay', [
            'booking' => $booking,
            'payment' => $payment,
            'account' => $account,
            'qrUrl' => $qrUrl,
            'proof' => $proof,
        ]);
    }

    public function submit(
        Request $request,
        Booking $booking
    ): RedirectResponse {
        $this->authorize('view', $booking);

        $payment = $booking->payments()
            ->where('provider', 'manual_gcash')
            ->latest()
            ->first();

        if ($payment === null) {
            abort(404);
        }

        $data = $request->validate([
            'reference_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('payment_proofs', 'reference_number'),
            ],
            'sender_name' => [
                'required',
                'string',
                'max:120',
            ],
            'amount_sent' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'proof_image' => [
                'required',
                'file',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:4096',
            ],
        ]);

        /*
         * ONE RECEIPT AT A TIME, AND NONE ONCE IT IS SETTLED.
         *
         * Nothing stopped a guest sending a second receipt while the
         * first was still with the resort, or another after it had been
         * approved. The unique rule on reference_number above only stops
         * the SAME reference twice; a different one went straight
         * through, and the owner's queue filled with receipts for one
         * payment with no way to tell which was the real one.
         *
         * AFTER VALIDATION, NOT BEFORE. A resent duplicate reference is a
         * more specific complaint than "one at a time", and it belongs on
         * the field the guest typed it into — checking this first would
         * have replaced that message with a vaguer one.
         *
         * A rejected receipt is deliberately not caught: sending a
         * corrected one is the whole point of giving a reason.
         */
        if ($payment->status === PaymentStatus::Paid) {
            return back()->with('error', 'This payment has already been verified by the resort.');
        }

        $waiting = $payment->proofs()
            ->where('status', PaymentProof::SUBMITTED)
            ->exists();

        if ($waiting) {
            return back()->with(
                'error',
                'You have already sent a receipt for this payment, and the resort is still checking it. '
                .'They will let you know once it has been reviewed.'
            );
        }

        $this->manualPayments->submit(
            $payment,
            $data,
            $request->file('proof_image')
        );

        return redirect()
            ->route('tourist.manual-payments.show', $booking)
            ->with(
                'status',
                'Your GCash proof has been sent. The resort will review it and confirm when it is received.'
            );
    }
}
