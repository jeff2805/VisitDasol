<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

        $proof->payment->booking->resort->id === $request->user()->resort()->value('id') ?: abort(403);

        $proof->payment->booking->payments()->where('provider', 'manual_gcash')->where('status', 'pending')->update(['status' => 'paid', 'method' => 'gcash', 'paid_at' => now()]);
        $proof->update(['status' => PaymentProof::APPROVED, 'review_note' => null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return back()->with('status', 'Payment approved. The guest will see it as paid.');
    }

    public function reject(Request $request, PaymentProof $proof): RedirectResponse
    {
        $this->authorize('reject', $proof);

        $request->validate(['review_note' => ['required', 'string', 'max:250']]);
        $proof->update(['status' => PaymentProof::REJECTED, 'review_note' => trim($request->string('review_note')->value()), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

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
        abort_unless($proof->payment->booking->resort->owner_id === auth()->id(), 403);

        abort_if(blank($proof->proof_image), 404);
        abort_unless(Storage::disk('local')->exists($proof->proof_image), 404);

        return Storage::disk('local')->response($proof->proof_image);
    }
}
