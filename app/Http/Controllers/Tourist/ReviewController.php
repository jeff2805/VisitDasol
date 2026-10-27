<?php

namespace App\Http\Controllers\Tourist;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tourist\ReviewRequest;
use App\Models\Booking;
use App\Models\Review;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * A guest reviewing a stay they have finished.
 *
 * The review is attached to the booking, and the booking decides everything:
 * which resort is being reviewed, who is writing, and whether they are
 * entitled to. None of that is read from the form.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(Booking $booking): View|RedirectResponse
    {
        $this->authorize('view', $booking);

        // Already written: send them to the edit form rather than a refusal.
        if ($booking->review()->exists()) {
            return redirect()->route('tourist.reviews.edit', $booking);
        }

        $this->authorize('create', [Review::class, $booking]);

        $booking->load('resort');

        return view('tourist.reviews.create', [
            'booking' => $booking,
            'review' => new Review,
        ]);
    }

    public function store(ReviewRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('create', [Review::class, $booking]);

        $review = new Review($request->validated());

        // Taken from the booking, never from the form.
        $review->forceFill([
            'booking_id' => $booking->id,
            'resort_id' => $booking->resort_id,
            'user_id' => $booking->user_id,
        ])->save();

        $this->logger->log(
            'review.created',
            $review,
            "Reviewed {$booking->resort?->name} after {$booking->booking_reference}",
            ['rating' => $review->rating],
            $request->user(),
        );

        return redirect()->route('tourist.bookings.show', $booking)
            ->with('success', 'Thank you. Your review is now on the resort page.');
    }

    public function edit(Booking $booking): View|RedirectResponse
    {
        $review = $booking->review;

        if ($review === null) {
            return redirect()->route('tourist.reviews.create', $booking);
        }

        $this->authorize('update', $review);

        $booking->load('resort');

        return view('tourist.reviews.create', [
            'booking' => $booking,
            'review' => $review,
        ]);
    }

    public function update(ReviewRequest $request, Booking $booking): RedirectResponse
    {
        $review = $booking->review;
        abort_if($review === null, 404);

        $this->authorize('update', $review);

        $review->fill($request->validated())->save();

        return redirect()->route('tourist.bookings.show', $booking)
            ->with('success', 'Your review has been updated.');
    }
}
