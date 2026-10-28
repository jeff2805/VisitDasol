<?php

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPaymentService
{
    public function isAvailable(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $this->dueNow($booking) > 0
            && $booking->resort->manualAccount()->where('active', true)->exists();
    }

    public function outstanding(Booking $booking): float
    {
        $paid = (float) $booking->payments()->where('provider', 'manual_gcash')->where('status', PaymentStatus::Paid->value)->sum('amount');

        return max(0, round((float) $booking->total_amount - $paid, 2));
    }

    /**
     * What the resort asks for before arrival, less what GCash has
     * already settled. A resort taking a deposit must not have its
     * guests sent a QR for the whole bill.
     */
    public function dueNow(Booking $booking): float
    {
        $required = $booking->amount_due_now === null
            ? (float) $booking->total_amount
            : min((float) $booking->amount_due_now, (float) $booking->total_amount);

        $paid = (float) $booking->payments()
            ->where('provider', 'manual_gcash')
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        return max(0, round($required - $paid, 2));
    }

    public function start(Booking $booking): Payment
    {
        $owed = $this->dueNow($booking);

        if ($booking->status !== BookingStatus::Confirmed || $owed <= 0) {
            throw PaymentException::nothingToPay();
        }

        $account = $booking->resort->manualAccount()->where('active', true)->first();

        if ($account === null) {
            throw PaymentException::notConfigured('GCash');
        }

        return $booking->payments()->create([
            'payment_reference' => 'VDP-MANUAL-'.Str::upper(Str::random(8)),
            'provider' => 'manual_gcash',
            'amount' => number_format($owed, 2, '.', ''),
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function submit(Payment $payment, array $data, UploadedFile $file): PaymentProof
    {
        /*
         * THE PRIVATE DISK, NOT THE PUBLIC ONE.
         *
         * A GCash receipt screenshot carries the guest's name, their
         * mobile number, the amount and the reference. On the public
         * disk Apache serves it straight off storage/app/public with no
         * authentication at all — the ownership check in
         * PaymentProofController::image() would have been guarding a
         * door that stood open beside it. The random filename is not a
         * defence; it is one leaked URL from being none.
         *
         * It is served only through PaymentProofController::image(),
         * which authorises before it reads a byte.
         */
        $path = $file->store('manual-payment-proofs', 'local');

        return $payment->proofs()->create([
            'reference_number' => trim((string) ($data['reference_number'] ?? '')),
            'sender_name' => trim((string) ($data['sender_name'] ?? '')),
            'amount_sent' => (float) ($data['amount_sent'] ?? 0),
            'proof_image' => $path,
            'status' => PaymentProof::SUBMITTED,
        ]);
    }

    public function approve(PaymentProof $proof): void
    {
        $payment = $proof->payment;

        $proof->forceFill([
            'status' => PaymentProof::APPROVED,
            'review_note' => null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'method' => 'gcash',
            'paid_at' => now(),
        ])->save();
    }

    public function reject(PaymentProof $proof, string $reason): void
    {
        $proof->forceFill([
            'status' => PaymentProof::REJECTED,
            'review_note' => trim($reason),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();
    }
}
