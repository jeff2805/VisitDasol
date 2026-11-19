<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * 1. Check email and password (rate limited, timing-safe).
     * 2. Refuse accounts that are not active, with a status-specific message.
     *    This message appears only after the password is correct, so it
     *    cannot be used to discover which emails are registered.
     * 3. Send a one-time code and stop. NOBODY IS SIGNED IN HERE.
     *
     * The password is only the first of two things asked for. Auth::login()
     * happens in LoginOtpController, once the code out of the mailbox has
     * been typed as well — so a stolen password on its own opens nothing.
     *
     * With the code switched off (see visitdasol.otp.require_on_login) the
     * password alone signs in exactly as it used to.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->verifiedUser();

        if (! $user->isActive()) {
            return back()
                ->onlyInput('email')
                ->with($user->status === UserStatus::Rejected ? 'error' : 'warning', $this->refusalMessage($user));
        }

        if (LoginOtpController::isRequired()) {
            return LoginOtpController::begin($request, $user);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'You have been signed out.');
    }

    private function refusalMessage(User $user): string
    {
        $message = $user->status->loginMessage();

        if ($user->status === UserStatus::Rejected && $user->isResortOwner()) {
            $remarks = $user->ownerProfile()->value('review_remarks');

            if (filled($remarks)) {
                $message .= ' Reason given: '.$remarks;
            }
        }

        return $message;
    }
}
