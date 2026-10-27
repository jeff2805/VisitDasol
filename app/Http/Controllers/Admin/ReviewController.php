<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Review moderation for the tourism office.
 *
 * Reviews go up as they are written and come down afterwards if they have to.
 * Hiding never deletes: the row stays, with who hid it and why, so a decision
 * to silence a guest can always be looked at again.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Review::class);

        $status = ReviewStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q', ''));

        return view('admin.reviews.index', [
            'reviews' => Review::query()
                ->when($status, fn ($query) => $query->where('status', $status->value))
                ->when($search !== '', fn ($query) => $query->where('comment', 'like', "%{$search}%"))
                ->with(['author:id,first_name,last_name', 'resort:id,name,slug', 'hiddenBy:id,first_name,last_name'])
                ->latest()
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'status' => $status,
            'search' => $search,
            'counts' => [
                ReviewStatus::Published->value => Review::query()->published()->count(),
                ReviewStatus::Hidden->value => Review::query()->hidden()->count(),
            ],
        ]);
    }

    public function hide(Request $request, Review $review): RedirectResponse
    {
        $this->authorize('moderate', $review);

        $data = $request->validate([
            'hidden_reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'hidden_reason.required' => 'Say why this review is being taken down.',
        ]);

        $review->forceFill([
            'status' => ReviewStatus::Hidden,
            'hidden_by' => $request->user()->id,
            'hidden_at' => now(),
            'hidden_reason' => $data['hidden_reason'],
        ])->save();

        $this->logger->logStatusChange(
            'review.hidden',
            $review,
            'Hid a review of '.($review->resort?->name ?? 'a resort'),
            ReviewStatus::Published->value,
            ReviewStatus::Hidden->value,
            ['reason' => $data['hidden_reason']],
        );

        return back()->with('success', 'That review is no longer shown to visitors.');
    }

    public function restore(Request $request, Review $review): RedirectResponse
    {
        $this->authorize('moderate', $review);

        $review->forceFill([
            'status' => ReviewStatus::Published,
            'hidden_by' => null,
            'hidden_at' => null,
            'hidden_reason' => null,
        ])->save();

        $this->logger->logStatusChange(
            'review.restored',
            $review,
            'Restored a review of '.($review->resort?->name ?? 'a resort'),
            ReviewStatus::Hidden->value,
            ReviewStatus::Published->value,
        );

        return back()->with('success', 'That review is visible again.');
    }
}
