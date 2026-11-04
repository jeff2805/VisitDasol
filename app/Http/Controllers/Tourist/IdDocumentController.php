<?php

namespace App\Http\Controllers\Tourist;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tourist\IdDocumentRequest;
use App\Models\Booking;
use App\Services\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The guest's own valid ID: sending one, and looking at the one they sent.
 *
 * Both actions authorise against BookingPolicy first. The file is on the
 * private disk, so this class is the whole of the access control over it —
 * there is no URL that reaches the bytes without coming through here.
 */
class IdDocumentController extends Controller
{
    public function __construct(
        private readonly IdentityVerificationService $identity,
    ) {}

    public function store(IdDocumentRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('uploadId', $booking);

        try {
            $this->identity->submit($booking, $request->file('id_document'));
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Your valid ID has been sent to the resort. They will review it before you arrive.'
        );
    }

    /**
     * The guest's own document, back to them.
     *
     * Served inline through the framework rather than by the web server,
     * with nosniff so a browser cannot be talked into treating it as
     * something executable.
     */
    public function show(Booking $booking): StreamedResponse
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
}
