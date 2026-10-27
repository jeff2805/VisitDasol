<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResortStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectResortRequest;
use App\Models\Resort;
use App\Services\ResortApprovalChecklist;
use App\Services\ResortManager;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resort listings waiting for the Municipal Tourism Office.
 *
 * A listing becomes public only here. The checklist is enforced on the
 * server, so approving by sending the request directly still fails while
 * a required item (active owner, cover photo, pin inside Dasol) is missing.
 */
class ResortApplicationController extends Controller
{
    public function __construct(
        private readonly ResortManager $resorts,
        private readonly ResortApprovalChecklist $checklist,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Resort::class);

        $status = ResortStatus::tryFrom((string) $request->query('status')) ?? ResortStatus::Pending;

        if (! in_array($status, [ResortStatus::Pending, ResortStatus::Rejected], true)) {
            $status = ResortStatus::Pending;
        }

        // Longest wait first stays the default: this is a queue, and the
        // application that has been waiting since Tuesday should be answered
        // before the one that arrived an hour ago.
        $sort = TableSort::fromRequest($request, [
            'waiting' => 'submitted_at',
            'name' => 'name',
            'barangay' => ['barangay', 'name'],
            'rooms' => '-rooms_count',
        ], default: 'waiting');

        return view('admin.resort-applications.index', [
            'status' => $status,
            'sort' => $sort,
            'applications' => Resort::query()
                ->withStatus($status)
                ->with(['owner.ownerProfile'])
                ->withCount('rooms')
                ->tap(fn ($query) => $sort->apply($query))
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'counts' => [
                ResortStatus::Pending->value => Resort::pending()->count(),
                ResortStatus::Rejected->value => Resort::withStatus(ResortStatus::Rejected)->count(),
            ],
        ]);
    }

    public function show(Resort $resort): View
    {
        $this->authorize('review', $resort);

        $resort->load(['owner.ownerProfile', 'images', 'amenities', 'rooms']);

        return view('admin.resort-applications.show', [
            'resort' => $resort,
            'checklist' => $this->checklist->for($resort),
            'blockers' => $this->checklist->blockers($resort),
        ]);
    }

    public function approve(Resort $resort): RedirectResponse
    {
        $this->authorize('approve', $resort);

        $blockers = $this->checklist->blockers($resort);

        if ($blockers !== []) {
            return back()->with('error', 'This listing cannot be approved yet: '.implode('; ', $blockers).'.');
        }

        $this->resorts->approve($resort);

        return redirect()
            ->route('admin.resort-applications.index')
            ->with('success', "{$resort->name} is now live on Visit Dasol.");
    }

    public function reject(RejectResortRequest $request, Resort $resort): RedirectResponse
    {
        $this->authorize('reject', $resort);

        $this->resorts->reject($resort, $request->validated('review_remarks'));

        return redirect()
            ->route('admin.resort-applications.index')
            ->with('success', "{$resort->name} was sent back to its owner with your notes.");
    }
}
