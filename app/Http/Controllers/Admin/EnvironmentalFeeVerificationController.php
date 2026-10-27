<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\EnvironmentalFeeException;
use App\Http\Controllers\Controller;
use App\Models\EnvironmentalFeeCollection;
use App\Models\EnvironmentalFeePolicy;
use App\Services\EnvironmentalFeeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Checking that GCash transfers actually arrived.
 *
 * This screen exists because the portal cannot do the checking itself.
 * There is no connection to a GCash wallet; somebody from the office
 * opens the app, finds the reference, and says what they found. Until
 * they do, the money is not in any total.
 *
 * The office's work, not a collector's: somebody able to confirm their
 * own transfers could record a fee, mark it arrived, and pocket the
 * cash instead.
 */
class EnvironmentalFeeVerificationController extends Controller
{
    public function __construct(private readonly EnvironmentalFeeService $fees) {}

    public function index(): View
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        return view('admin.environmental-fee.verify', [
            'waiting' => EnvironmentalFeeCollection::query()
                ->awaitingVerification()
                ->with(['checkpoint:id,name,barangay', 'collector:id,first_name,last_name'])
                ->oldest('collected_at')
                ->get(),

            // What was decided recently, so a mistake is easy to spot.
            'decided' => EnvironmentalFeeCollection::query()
                ->where('payment_method', 'gcash')
                ->whereNotNull('verified_at')
                ->with(['checkpoint:id,name', 'verifiedBy:id,first_name,last_name'])
                ->latest('verified_at')
                ->limit(25)
                ->get(),
        ]);
    }

    /** Confirm a transfer, or say it never came. */
    public function settle(Request $request, EnvironmentalFeeCollection $collection): RedirectResponse
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        $data = $request->validate([
            'arrived' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->fees->settle(
                $collection,
                $request->user(),
                $request->boolean('arrived'),
                (string) ($data['note'] ?? ''),
            );
        } catch (EnvironmentalFeeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $request->boolean('arrived')
            ? "{$collection->reference} is confirmed and now counts towards the totals."
            : "{$collection->reference} is marked as not received. It does not count towards any total.");
    }

    /**
     * The payer's screenshot.
     *
     * Served through here rather than from a public URL: it carries a
     * stranger's GCash account name and balance, and a guessable path
     * on the public disk would hand it to anybody.
     */
    public function proof(EnvironmentalFeeCollection $collection): StreamedResponse
    {
        $this->authorize('viewReports', EnvironmentalFeePolicy::class);

        abort_if(blank($collection->proof_image), 404);
        abort_unless(Storage::disk('local')->exists($collection->proof_image), 404);

        return Storage::disk('local')->response($collection->proof_image);
    }
}
