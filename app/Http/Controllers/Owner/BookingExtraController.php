<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ExtraStatus;
use App\Http\Controllers\Controller;
use App\Models\BookingExtra;
use App\Services\BookingExtraService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The resort answering what a guest asked for.
 *
 * Yes or no, with a reason when it is no. Authorized against the
 * BOOKING, so an owner can only answer requests made against their own
 * resort however the URL is edited.
 */
class BookingExtraController extends Controller
{
    public function __construct(private readonly BookingExtraService $extras) {}

    public function answer(Request $request, BookingExtra $extra): RedirectResponse
    {
        // The booking is the thing being managed; the extra hangs off it.
        $this->authorize('manageExtras', $extra->booking);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                ExtraStatus::Confirmed->value,
                ExtraStatus::Declined->value,
            ])],

            /*
             * A reason is required to refuse. A guest who planned a day
             * around a banana boat needs to know whether it is the
             * weather, the boat or the date — "no" on its own is the
             * message that generates a phone call.
             */
            'owner_note' => [
                'nullable', 'string', 'max:500',
                Rule::requiredIf($request->input('status') === ExtraStatus::Declined->value),
            ],
        ], [
            'owner_note.required' => 'Say why you cannot do this, so the guest can plan around it.',
        ]);

        $status = ExtraStatus::from($validated['status']);

        $this->extras->answer(
            $extra,
            $request->user(),
            $status,
            $validated['owner_note'] ?? null,
        );

        return back()->with('success', $status === ExtraStatus::Confirmed
            ? "{$extra->name} is confirmed for this guest."
            : "{$extra->name} has been marked unavailable. The guest can see your reason.");
    }
}
