<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateUserRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AccountManager;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tourist and owner accounts. The office can look them up and switch them
 * on or off, but can never change a role or act on another office account.
 */
class UserController extends Controller
{
    public function __construct(private readonly AccountManager $accounts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $role = UserRole::tryFrom((string) $request->query('role'));
        $status = UserStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q'));

        $sort = TableSort::fromRequest($request, [
            'name' => ['last_name', 'first_name'],
            'email' => 'email',
            'role' => 'role',
            'status' => 'status',
            'registered' => '-created_at',
            // Nobody has signed in on a brand new account, and null sorts
            // first ascending, so the useful end of this column is the recent
            // one: desc puts the people who were here today at the top.
            'signed_in' => '-last_login_at',
        ], default: 'registered');

        $users = User::query()
            ->when($role, fn ($query) => $query->withRole($role))
            ->when($status, fn ($query) => $query->withStatus($status))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($match) use ($search) {
                    $match->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with('ownerProfile')
            ->tap(fn ($query) => $sort->apply($query))
            ->paginate(config('visitdasol.per_page'))
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'role' => $role,
            'status' => $status,
            'search' => $search,
            'sort' => $sort,
        ]);
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $user->load(['ownerProfile.reviewer', 'resorts']);

        return view('admin.users.show', [
            'user' => $user,
            'history' => ActivityLog::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->id)
                ->with('user')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function activate(User $user): RedirectResponse
    {
        $this->authorize('activate', $user);

        $this->accounts->activate($user);

        return back()->with('success', "{$user->full_name} can sign in again.");
    }

    public function deactivate(DeactivateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $this->accounts->deactivate($user, $request->validated('reason'));

        $note = $user->isResortOwner() && $user->resorts()->exists()
            ? ' Their resort is now hidden from the public.'
            : '';

        return back()->with('success', "{$user->full_name} has been signed out and deactivated.".$note);
    }
}
