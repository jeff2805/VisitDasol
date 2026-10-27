<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TouristRegistrationRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class TouristRegistrationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function create(): View
    {
        return view('auth.register-tourist');
    }

    /**
     * Tourist accounts are active immediately and signed in right away, but
     * the address has not been proved yet: a one-time code is emailed and the
     * "verified" middleware holds the account at that screen until it is
     * entered. Signing them in first means the code screen knows who they are.
     */
    public function store(TouristRegistrationRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only([
            'first_name', 'last_name', 'email', 'phone', 'home_city', 'home_country', 'password',
        ]));
        $user->role = UserRole::Tourist;
        $user->status = UserStatus::Active;
        $user->save();

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        $this->verification->send($user);

        return redirect()->route('verification.notice')
            ->with('info', "Welcome, {$user->first_name}! Enter the code we sent to {$user->email} to finish.");
    }
}
