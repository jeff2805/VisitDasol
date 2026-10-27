<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ManualGcashController extends Controller
{
    public function edit(Request $request): View
    {
        $resort = $request->user()->resort()->firstOrFail();
        $account = $resort->manualAccount()->first();

        return view('owner.manual-gcash.edit', [
            'resort' => $resort,
            'account' => $account,
            'qrUrl' => $account?->qr_image
                ? asset('storage/' . $account->qr_image)
                : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $resort = $request->user()->resort()->firstOrFail();

        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:120'],
            'gcash_number' => ['required', 'string', 'max:30'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'qr_image' => [
                'nullable',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
            ],
            'remove_qr' => ['nullable', 'boolean'],
        ]);

        $account = $resort->manualAccount()->firstOrNew();

        $account->fill([
            'account_name' => trim($data['account_name']),
            'gcash_number' => preg_replace(
                '/\D+/',
                '',
                $data['gcash_number']
            ),
            'instructions' => trim($data['instructions'] ?? '') ?: null,
            'active' => $request->boolean('active'),
        ]);

        if ($request->boolean('remove_qr')) {
            if ($account->qr_image) {
                Storage::disk('public')->delete($account->qr_image);
            }

            $account->qr_image = null;
        }

        if ($request->hasFile('qr_image')) {
            if ($account->qr_image) {
                Storage::disk('public')->delete($account->qr_image);
            }

            $account->qr_image = $request
                ->file('qr_image')
                ->store('manual-gcash-qr', 'public');
        }

        $account->resort()->associate($resort);
        $account->save();

        return redirect()
            ->route('owner.manual-gcash.edit')
            ->with('status', 'Your GCash account details have been saved.');
    }
}