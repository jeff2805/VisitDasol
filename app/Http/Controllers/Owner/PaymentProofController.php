<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use App\Enums\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentProofController extends Controller
{
    public function index(Request $request): View
    {
        $resort = $request->user()->resort()->firstOrFail();

        return view('manual-gcash.review', [
            'resort' => $resort,
            'waiting' => $resort->bookings()->with('payments.proofs')->get()->flatMap(fn ($booking) => $booking->payments->flatMap(fn ($payment) => $payment->proofs->map(fn ($proof) => $proof)))->filter(fn ($proof) => $proof->status === PaymentProof::SUBMITTED)->values(),
            'recent' => $resort->bookings()->with('payments.proofs')->get()->flatMap(fn ($booking) => $booking->payments->flatMap(fn ($payment) => $payment->proofs->map(fn ($proof) => $proof)))->filter(fn ($proof) => $proof->status !== PaymentProof::SUBMITTED)->values(),
        ]);
    }

    public function approve(Request $request, PaymentProof $proof): RedirectResponse
    {
        $this->authorize('approve', $proof);

        DB::transaction(function () use ($proof, $request): void {
            $lockedProof = PaymentProof::query()->whereKey($proof->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedProof->status === PaymentProof::SUBMITTED, 409, 'This proof has already been reviewed.');

            $payment = $lockedProof->payment()->lockForUpdate()->firstOrFail();
            abort_unless($payment->provider->value === 'manual_gcash', 422, 'This proof is not attached to a manual GCash payment.');
            abort_unless($payment->status === PaymentStatus::Pending, 409, 'This payment is no longer awaiting verification.');

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'method' => 'gcash',
                'paid_at' => now(),
            ])->save();

            $lockedProof->forceFill([
                'status' => PaymentProof::APPROVED,
                'review_note' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
        });

        return back()->with('status', 'Payment approved. The guest will see it as paid.');
    }

    public function reject(Request $request, PaymentProof $proof): RedirectResponse
    {
        $this->authorize('reject', $proof);

        $data = $request->validate(['review_note' => ['required', 'string', 'max:250']]);

        DB::transaction(function () use ($proof, $request, $data): void {
            $lockedProof = PaymentProof::query()->whereKey($proof->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedProof->status === PaymentProof::SUBMITTED, 409, 'This proof has already been reviewed.');

            $lockedProof->forceFill([
                'status' => PaymentProof::REJECTED,
                'review_note' => trim($data['review_note']),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
        });

        return back()->with('status', 'The guest has been asked to send a new proof.');
    }

    /**
     * The guest's receipt screenshot, served only to the resort it was
     * sent to.
     *
     * Reads the private disk. The upload used to land on the public one,
     * where Apache served it with no authentication whatsoever and this
     * ownership check guarded nothing. A row with no file, or a row whose
     * file has gone, is a 404 and not a 500.
     */
    public function image(PaymentProof $proof)
    {
        $this->authorize('view', $proof);

        abort_if(blank($proof->proof_image), 404);
        abort_unless(Storage::disk('local')->exists($proof->proof_image), 404);

        return Storage::disk('local')->response($proof->proof_image);
    }
}
