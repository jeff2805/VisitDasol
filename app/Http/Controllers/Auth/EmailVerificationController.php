<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Services\EmailVerificationService;
use App\Services\VerificationResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The one-time code screen a new account sees before it may use the site.
 *
 * Reached by the "verified" middleware, and by the tourist sign-up form, which
 * sends the first code. An account that has already confirmed its address is
 * sent to its dashboard instead of being asked again.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $service) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->route('dashboard');
        }

        // No code waiting (the screen was reopened much later, or this is the
        // first sign-in of an older account): send one now.
        if ($this->service->outstanding($user) === null) {
            $this->service->send($user);
        }

        // Read back afterwards, so a code that was just sent reports its own
        // cooldown and expiry rather than the one it replaced.
        $code = $this->service->outstanding($user);

        return view('auth.verify-email', [
            'email' => $user->email,
            'secondsUntilResend' => $code?->secondsUntilResend() ?? 0,
            'secondsUntilExpiry' => $code?->secondsUntilExpiry() ?? 0,
            'attemptsLeft' => $code?->attemptsLeft() ?? (int) config('visitdasol.otp.max_attempts'),
        ]);
    }

    public function verify(VerifyEmailCodeRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->route('dashboard');
        }

        $result = $this->service->verify($user, $request->validated('code'));

        if (! $result->isVerified()) {
            $message = $result->message();

            /*
             * A mistyped code is the only refusal that leaves the code alive,
             * so it is the only one where the number of tries left is worth
             * saying. Every other case has already thrown the code away and
             * tells the person to ask for a new one.
             */
            if ($result === VerificationResult::Incorrect) {
                $left = $this->service->outstanding($user)?->attemptsLeft() ?? 0;

                $message .= ' '.($left === 1
                    ? 'One more wrong code and you will need a new one.'
                    : "You have {$left} tries left before you need a new code.");
            }

            return back()
                ->withErrors(['code' => $message])
                ->with($result->needsNewCode() ? 'warning' : 'error', $message);
        }

        // A new privilege level begins here, so the session id must not be
        // one an attacker could have planted before sign-up.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('success', "Thanks, {$user->first_name}! Your email address is confirmed.");
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return redirect()->route('dashboard');
        }

        $wait = $this->service->secondsUntilResend($user);

        if ($wait > 0) {
            return back()->with('warning', "Please wait {$wait} more seconds before asking for another code.");
        }

        $this->service->send($user);

        return back()->with('success', "A new code is on its way to {$user->email}.");
    }
}
