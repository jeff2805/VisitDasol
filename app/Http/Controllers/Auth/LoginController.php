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
     * 3. Sign in, start a fresh session, and go to the right dashboard.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->verifiedUser();

        if (! $user->isActive()) {
            return back()
                ->onlyInput('email')
                ->with($user->status === UserStatus::Rejected ? 'error' : 'warning', $this->refusalMessage($user));
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
