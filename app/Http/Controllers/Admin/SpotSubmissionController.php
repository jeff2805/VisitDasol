<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SpotDecisionRequest;
use App\Models\Destination;
use App\Services\TouristSpotSubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The queue of destinations the barangays have sent in.
 *
 * Nothing here is on the public site: a submission is invisible until the
 * office approves it. This screen is where that decision is made, and the
 * reason for sending one back is written for the barangay to read and act on.
 */
class SpotSubmissionController extends Controller
{
    public function __construct(private readonly TouristSpotSubmissionService $submissions) {}

    public function index(): View
    {
        $this->authorize('viewAny', Destination::class);

        return view('admin.spot-submissions.index', [
            'submissions' => Destination::query()
                ->pending()
                ->with(['category', 'creator'])
                ->withCount('images')
                ->oldest('submitted_at')
                ->paginate(config('visitdasol.per_page')),
        ]);
    }

    public function show(Destination $spot): View
    {
        $this->authorize('viewAny', Destination::class);

        $spot->load(['category', 'creator', 'images', 'reviewer']);

        return view('admin.spot-submissions.show', ['spot' => $spot]);
    }

    public function approve(SpotDecisionRequest $request, Destination $spot): RedirectResponse
    {
        $this->authorize('review', $spot);

        $this->submissions->approve($request->user(), $spot, $request->input('review_remarks'));

        return redirect()->route('admin.spot-submissions.index')
            ->with('success', "{$spot->name} is now on the site. Barangay {$spot->barangay} has been told.");
    }

    public function reject(SpotDecisionRequest $request, Destination $spot): RedirectResponse
    {
        $this->authorize('review', $spot);

        $this->submissions->reject($request->user(), $spot, (string) $request->validated('review_remarks'));

        return redirect()->route('admin.spot-submissions.index')
            ->with('success', "{$spot->name} has been sent back to Barangay {$spot->barangay} with your note.");
    }
}
