<?php

namespace App\Http\Controllers\Owner;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\RejectIdDocumentRequest;
use App\Models\Booking;
use App\Services\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The front desk's side of the valid ID: look at it, decide on it, and the
 * two moments it is checked against — arrival and departure.
 *
 * EVERY ACTION AUTHORISES AGAINST BookingPolicy, WHICH ASKS WHETHER THIS
 * OWNER OWNS THE RESORT THE BOOKING IS AT. A reference belonging to another
 * resort is a 403, not a different page, and the document action refuses
 * before it opens the disk. Hiding the buttons in Blade is not part of the
 * protection and nothing here relies on it.
 */
class GuestIdentityController extends Controller
{
    public function __construct(
        private readonly IdentityVerificationService $identity,
    ) {}

    /**
     * The guest's ID, streamed from the private disk.
     *
     * Reaches storage only after the policy has said yes, so an owner
     * guessing another resort's booking reference never causes a read.
     */
    public function document(Booking $booking): StreamedResponse
    {
        $this->authorize('viewId', $booking);

        $path = $booking->id_document_path;
        abort_if(blank($path), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            'valid-id-'.$booking->booking_reference.'.'.pathinfo($path, PATHINFO_EXTENSION),
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function approve(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('verifyId', $booking);

        return $this->run(
            fn () => $this->identity->approve($booking, $request->user()),
            'The ID is verified. This guest can now be checked in.'
        );
    }

    public function reject(RejectIdDocumentRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('verifyId', $booking);

        return $this->run(
            fn () => $this->identity->reject($booking, $request->user(), $request->validated('reason')),
            'The guest has been asked to send another ID, with your reason.'
        );
    }

    public function checkIn(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('checkIn', $booking);

        return $this->run(
            fn () => $this->identity->checkIn($booking, $request->user()),
            "{$booking->booking_reference} is checked in."
        );
    }

    public function checkOut(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('checkOut', $booking);

        return $this->run(
            fn () => $this->identity->checkOut($booking, $request->user()),
            "{$booking->booking_reference} is checked out."
        );
    }

    /**
     * Runs one of these and turns a refusal into a message on the page,
     * rather than an error screen. The same shape BookingController uses,
     * because the refusals are the same kind of thing: a rule the desk
     * needs explained, not a fault.
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
