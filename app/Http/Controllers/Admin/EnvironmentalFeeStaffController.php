<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnvironmentalFeeStaffRequest;
use App\Models\EnvironmentalFeeCheckpoint;
use App\Models\User;
use App\Services\EnvironmentalFeeStaffService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

/**
 * The people who work the environmental fee.
 *
 * The office's screen and nobody else's. Every action authorizes
 * against UserPolicy on the server as well as sitting behind the
 * role middleware on the route, because a hidden menu item is not
 * authorisation.
 *
 * Activating and deactivating are NOT here. Those already exist on the
 * accounts screen, go through AccountManager — which ends the person's
 * live sessions and writes the audit entry — and a second copy of that
 * logic is a second place for it to drift.
 */
class EnvironmentalFeeStaffController extends Controller
{
    public function __construct(private readonly EnvironmentalFeeStaffService $staff) {}

    public function index(): View
    {
        $this->authorize('viewStaff', User::class);

        return view('admin.environmental-fee.staff.index', [
            'staff' => User::query()
                ->environmentalFeeStaff()
                ->with(['feeAssignments' => fn ($query) => $query
                    ->where('active', true)
                    ->with('checkpoint:id,name,barangay')])
                ->orderBy('role')
                ->orderBy('last_name')
                ->paginate(config('visitdasol.per_page')),

            'roles' => UserRole::staffOptions(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('createStaff', User::class);

        return view('admin.environmental-fee.staff.create', $this->formData(new User));
    }

    public function store(EnvironmentalFeeStaffRequest $request): RedirectResponse
    {
        $this->authorize('createStaff', User::class);

        $staff = $this->staff->create(
            $request->user(),
            $request->safe()->only([
                'first_name', 'last_name', 'email', 'phone', 'employee_id', 'role',
            ]),
            $request->checkpointIds(),
        );

        /*
         * Invited straight away. An account that exists but cannot be
         * signed into is a support call waiting to happen, and the
         * office would otherwise have to remember a second button.
         */
        $status = $this->staff->invite($request->user(), $staff);

        return redirect()
            ->route('admin.environmental-fee.staff.index')
            ->with('success', $status === Password::RESET_LINK_SENT
                ? "{$staff->full_name} has been sent a link to set their password. "
                    .'It expires in '.config('auth.passwords.users.expire', 60).' minutes.'
                : "{$staff->full_name}'s account was created, but the invitation could not be "
                    .'sent just now. Use "Send invitation" on their row to try again.');
    }

    public function edit(User $staff): View
    {
        $this->authorize('updateStaff', $staff);

        return view('admin.environmental-fee.staff.edit', $this->formData($staff));
    }

    public function update(EnvironmentalFeeStaffRequest $request, User $staff): RedirectResponse
    {
        $this->authorize('updateStaff', $staff);

        $this->staff->update(
            $request->user(),
            $staff,
            $request->safe()->only([
                'first_name', 'last_name', 'email', 'phone', 'employee_id', 'role',
            ]),
            $request->checkpointIds(),
        );

        return redirect()
            ->route('admin.environmental-fee.staff.index')
            ->with('success', "{$staff->full_name}'s details have been saved.");
    }

    /** Send, or resend, the link that lets somebody set a password. */
    public function invite(User $staff): RedirectResponse
    {
        $this->authorize('inviteStaff', $staff);

        $status = $this->staff->invite(request()->user(), $staff);

        return back()->with(
            $status === Password::RESET_LINK_SENT ? 'success' : 'error',
            match ($status) {
                Password::RESET_LINK_SENT => "A link has been sent to {$staff->email}. "
                    .'It expires in '.config('auth.passwords.users.expire', 60).' minutes.',
                Password::RESET_THROTTLED => 'A link was sent to this address a moment ago. '
                    .'Wait a minute before sending another.',
                default => 'The link could not be sent. Check the mail settings and the address.',
            }
        );
    }

    /**
     * What both forms need.
     *
     * Only active checkpoints are offered: putting somebody on a gate
     * the office has switched off would be an assignment that cannot
     * be used, and the request refuses it anyway.
     *
     * @return array<string, mixed>
     */
    private function formData(User $staff): array
    {
        return [
            'staff' => $staff,
            'roles' => UserRole::staffOptions(),
            'descriptions' => collect(UserRole::environmentalFeeStaff())
                ->mapWithKeys(fn (UserRole $role) => [$role->value => $role->description()])
                ->all(),
            'checkpoints' => EnvironmentalFeeCheckpoint::query()
                ->active()
                ->orderBy('barangay')
                ->orderBy('name')
                ->get(['id', 'name', 'barangay']),
            'assigned' => $staff->exists
                ? $staff->feeAssignments()->where('active', true)->pluck('checkpoint_id')->all()
                : [],
        ];
    }
}
