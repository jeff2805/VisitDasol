<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ManualGcashRequest;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * A resort's own GCash account.
 *
 * The resort always comes from the signed-in owner and never from the
 * request, and both actions are checked against ResortPolicy — the same
 * ability that guards the payment terms, because these are two halves of
 * one question: how this resort takes money.
 */
class ManualGcashController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function edit(Request $request): View
    {
        $resort = $request->user()->resort()->firstOrFail();

        $this->authorize('managePaymentPolicy', $resort);

        $account = $resort->manualAccount()->first();

        return view('owner.manual-gcash.edit', [
            'resort' => $resort,
            'account' => $account,

            // The model works the address out, so a remote public disk
            // and a missing file are both handled in one place.
            'qrUrl' => $account?->qr_url,
        ]);
    }

    public function update(ManualGcashRequest $request): RedirectResponse
    {
        $resort = $request->user()->resort()->firstOrFail();

        $this->authorize('managePaymentPolicy', $resort);

        $account = $resort->manualAccount()->firstOrNew();

        $wasActive = (bool) $account->active;
        $previousQr = $account->qr_image;

        $account->fill([
            'account_name' => $request->validated('account_name'),
            'gcash_number' => $request->gcashNumber(),
            'instructions' => $request->validated('instructions'),
            'active' => $request->boolean('active'),
        ]);

        /*
         * THE REPLACEMENT IS STORED BEFORE THE OLD ONE IS LET GO.
         *
         * This used to delete the existing QR and then call store(), so a
         * failed write — a full disk, a Supabase hiccup, a rejected
         * object — left the owner with no QR code at all and the column
         * set to store()'s `false`. The owner's guests then saw payment
         * instructions with no QR, and the only copy of it was gone.
         *
         * Now nothing is deleted until the new file is on the disk, and
         * the old path survives in the column if the write fails.
         */
        $storedQr = null;

        if ($request->hasFile('qr_image')) {
            $storedQr = $request->file('qr_image')->store('manual-gcash-qr', 'public');

            if ($storedQr === false || blank($storedQr)) {
                return back()
                    ->withInput()
                    ->with('error', 'That QR code could not be saved. Your previous one has been kept.');
            }

            $account->qr_image = $storedQr;
        } elseif ($request->boolean('remove_qr')) {
            // Only when no replacement came with it: a form that sends
            // both means "replace", and honouring the tickbox as well
            // would delete the file that had just been uploaded.
            $account->qr_image = null;
        }

        $account->resort()->associate($resort);

        try {
            $account->save();
        } catch (\Throwable $e) {
            // The row did not change, so the new file is an orphan.
            if ($storedQr !== null) {
                Storage::disk('public')->delete($storedQr);
            }

            throw $e;
        }

        /*
         * The old file goes only once the row that replaced it is
         * committed. Safe to be a no-op: $previousQr is null the first
         * time an owner sets this up.
         */
        if (filled($previousQr) && $previousQr !== $account->qr_image) {
            Storage::disk('public')->delete($previousQr);
        }

        /*
         * Logged like the payment terms beside it. Switching GCash off is
         * the kind of change a guest notices and nobody remembers making,
         * so it is worth a line in the trail. The number is not written
         * into it — the account row holds that, and a log is read by more
         * people than the settings page is.
         */
        $this->logger->log(
            'resort.manual_gcash_updated',
            $resort,
            "Updated the GCash account for {$resort->name}",
            [
                'active' => (bool) $account->active,
                'was_active' => $wasActive,
                'has_qr' => filled($account->qr_image),
            ],
        );

        return redirect()
            ->route('owner.manual-gcash.edit')
            ->with('status', 'Your GCash account details have been saved.');
    }
}
