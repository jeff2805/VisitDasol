<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnvironmentalFeeAccount;
use App\Models\EnvironmentalFeePolicy;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The municipality's GCash details for the environmental fee.
 *
 * Mirrors the resort's own GCash screen, deliberately: an office that
 * has set one up already knows how this one works. What it is NOT is a
 * payment gateway — a QR code is an instruction to the payer, and
 * nothing in this portal can see money reach a wallet. Every payment
 * made this way waits for somebody to open the app and confirm it.
 */
class EnvironmentalFeeAccountController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function edit(): View
    {
        $this->authorize('configure', EnvironmentalFeePolicy::class);

        $account = EnvironmentalFeeAccount::current();

        return view('admin.environmental-fee.gcash', [
            'account' => $account,
            'qrUrl' => $account?->qr_image
                ? Storage::disk('public')->url($account->qr_image)
                : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('configure', EnvironmentalFeePolicy::class);

        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:120'],
            'gcash_number' => ['required', 'string', 'max:30'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'qr_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_qr' => ['nullable', 'boolean'],
        ], [
            'account_name.required' => 'Enter the name the GCash account is registered to.',
            'gcash_number.required' => 'Enter the municipal GCash number.',
            'qr_image.image' => 'Upload a photo or screenshot of the QR code.',
        ]);

        $account = EnvironmentalFeeAccount::current() ?? new EnvironmentalFeeAccount;

        $account->fill([
            'account_name' => trim($data['account_name']),
            // Digits only, the way the resort screen stores them.
            'gcash_number' => preg_replace('/\D+/', '', $data['gcash_number']),
            'instructions' => trim($data['instructions'] ?? '') ?: null,
        ]);

        if ($request->boolean('remove_qr') && $account->qr_image) {
            Storage::disk('public')->delete($account->qr_image);
            $account->qr_image = null;
        }

        if ($request->hasFile('qr_image')) {
            if ($account->qr_image) {
                Storage::disk('public')->delete($account->qr_image);
            }

            $account->qr_image = $request->file('qr_image')
                ->store('environmental-fee-qr', 'public');
        }

        /*
         * Refused rather than quietly stored half-ready. Somebody at a
         * gate holding up a phone needs the QR to point at; a number
         * alone has them reading digits through a car window.
         */
        $wantsActive = $request->boolean('active');

        if ($wantsActive && blank($account->qr_image)) {
            return back()->withInput()->with(
                'error',
                'Upload the QR code before switching GCash on. Collectors need something '
                .'to show at the gate.'
            );
        }

        $wasActive = (bool) $account->active;
        $account->active = $wantsActive;
        $account->updated_by = $request->user()->id;
        $account->save();

        $this->logger->log(
            'environmental_fee.gcash_updated',
            $account,
            'Updated the municipal GCash details for the environmental fee',
            [
                'active' => $account->active,
                'was_active' => $wasActive,
                'has_qr' => filled($account->qr_image),
                // The number is municipal and public at the gate, but there
                // is no reason to copy it into the audit trail.
            ],
        );

        return redirect()->route('admin.environmental-fee.gcash.edit')->with(
            'success',
            $account->active
                ? 'GCash is on. Collectors can now show the QR and record transfers, '
                    .'which you confirm afterwards.'
                : 'Saved. GCash is off, so collectors take cash only.'
        );
    }
}
