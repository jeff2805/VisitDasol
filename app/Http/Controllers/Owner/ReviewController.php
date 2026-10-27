<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What guests said about this owner's resort, and the owner's answer.
 *
 * An owner can reply but cannot edit, hide, or delete a review. Being able to
 * remove criticism would make every remaining rating on the site worthless.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View|RedirectResponse
    {
        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create');
        }

        $this->authorize('view', $resort);

        return view('owner.reviews.index', [
            'resort' => $resort->loadCount(['reviews as rating_count' => fn ($q) => $q->published()])
                ->loadAvg(['reviews as rating_average' => fn ($q) => $q->published()], 'rating'),
            'reviews' => Review::query()
                ->forResort($resort)
                ->published()
                ->with('author:id,first_name,last_name')
                ->latest()
                ->paginate(config('visitdasol.per_page')),
        ]);
    }

    public function reply(Request $request, Review $review): RedirectResponse
    {
        $this->authorize('reply', $review);

        $data = $request->validate([
            'owner_reply' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'owner_reply.required' => 'Write your reply before sending it.',
            'owner_reply.min' => 'A reply of at least 10 characters is more use to a reader.',
        ]);

        $review->forceFill([
            'owner_reply' => trim($data['owner_reply']),
            'owner_replied_at' => now(),
        ])->save();

        $this->logger->log(
            'review.replied',
            $review,
            'Replied to a guest review',
            [],
            $request->user(),
        );

        return back()->with('success', 'Your reply is now shown under that review.');
    }
}
