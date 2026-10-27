<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectOwnerRequest;
use App\Models\User;
use App\Services\AccountManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Municipal Tourism Office reviews resort owner applications here.
 *
 * Every action re-reads the account from the database and checks UserPolicy,
 * so a status can never be changed by editing the form or the URL.
 */
class OwnerApplicationController extends Controller
{
    public function __construct(private readonly AccountManager $accounts) {}

    /** Queue of applications, filtered by status (pending by default). */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $status = UserStatus::tryFrom((string) $request->query('status')) ?? UserStatus::Pending;

        if (! in_array($status, [UserStatus::Pending, UserStatus::Rejected], true)) {
            $status = UserStatus::Pending;
        }

        return view('admin.owner-applications.index', [
            'status' => $status,
            'applications' => User::query()
                ->resortOwners()
                ->withStatus($status)
                ->with('ownerProfile')
                ->latest()
                ->paginate(config('visitdasol.per_page'))
                ->withQueryString(),
            'counts' => [
                UserStatus::Pending->value => User::pendingOwners()->count(),
                UserStatus::Rejected->value => User::resortOwners()->withStatus(UserStatus::Rejected)->count(),
            ],
        ]);
    }

    public function show(User $owner): View
    {
        $this->authorize('review', $owner);

        $owner->load(['ownerProfile.reviewer', 'resorts']);

        return view('admin.owner-applications.show', ['owner' => $owner]);
    }

    /**
     * Streams the applicant's permit document from the private disk.
     * The file is never reachable by URL; only this route can serve it.
     */
    public function permit(User $owner): StreamedResponse
    {
        $this->authorize('review', $owner);

        $path = $owner->ownerProfile()->first()?->permit_document_path;
        abort_if(blank($path), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, 'permit-'.$owner->id.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(User $owner): RedirectResponse
    {
        $this->authorize('approve', $owner);

        $this->accounts->approveOwner($owner);

        return redirect()
            ->route('admin.owner-applications.index')
            ->with('success', "{$owner->full_name} can now sign in and submit their resort.");
    }

    public function reject(RejectOwnerRequest $request, User $owner): RedirectResponse
    {
        $this->authorize('reject', $owner);

        $this->accounts->rejectOwner($owner, $request->validated('review_remarks'));

        return redirect()
            ->route('admin.owner-applications.index')
            ->with('success', "The application of {$owner->full_name} was turned down.");
    }

    /** Put a rejected application back into the pending queue. */
    public function reopen(User $owner): RedirectResponse
    {
        $this->authorize('reopen', $owner);

        $this->accounts->reopenOwner($owner);

        return redirect()
            ->route('admin.owner-applications.show', $owner)
            ->with('success', 'The application is waiting for review again.');
    }
}
