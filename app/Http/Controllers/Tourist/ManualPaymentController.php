<?php

namespace App\Http\Controllers\Tourist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\ManualPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

            $qrUrl = $account?->qr_image
    ? asset('storage/' . $account->qr_image)
    : null;

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
