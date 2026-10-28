<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every signed-in user manages their own profile here.
 * There is no user id in these URLs, so nobody can edit someone else.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('profile.edit', [
            'user' => $user,

            /*
             * The shell this page sits in, so somebody editing their
             * profile keeps the menu they arrived with.
             *
             * EVERY ROLE MUST APPEAR HERE. A match with no arm for a
             * role throws UnhandledMatchError, which is a 500 on the
             * one page every signed-in account is entitled to open.
             * barangay_official was missing from the day the sidebar
             * started linking here: an official who clicked Profile got
             * a blank error page and no way to change their password.
             *
             * Hence no default arm either. A role added to UserRole and
             * forgotten here should break the test suite, loudly, not
             * quietly inherit somebody else's navigation.
             */
            'layout' => match ($user->role) {
                UserRole::Tourist => 'layouts.public',
                UserRole::ResortOwner => 'layouts.owner',
                UserRole::BarangayOfficial => 'layouts.barangay',
                UserRole::SuperAdmin => 'layouts.admin',

                // Collectors and cashiers share the dashboard shell,
                // which picks their menu from their role.
                UserRole::FeeCollector, UserRole::Cashier => 'layouts.dashboard',
            },
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->profileData());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return redirect()->route('profile.edit')->with('success', 'Your profile has been updated.');
    }

    /**
     * Changes the password, invalidates "remember me" on other devices, and
     * (with database sessions) signs out every other session of this user.
     */
    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->setRememberToken(Str::random(60));
        $user->save();

        $request->session()->regenerate();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return redirect()->route('profile.edit')
            ->with('success', 'Your password has been changed. Other devices have been signed out.');
    }
}
