<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\VerificationResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The second half of signing in: the code sent to the mailbox.
 *
 * NOBODY IS SIGNED IN UNTIL verify() SAYS SO. Between the password and the
 * code the session holds nothing but an id, a timestamp and the "remember"
 * tick — no guard, no privileges, no way to reach a single page behind auth.
 * That is the whole security property, and it is why Auth::login() appears
 * exactly once in this file.
 *
 * THE WAITING STATE IS NOT A SESSION. It expires on its own (see
 * visitdasol.otp.login_window_minutes), so a browser left open on a shared
 * machine at the code screen is not a way in an hour later — the password
 * has to be typed again.
 */
class LoginOtpController extends Controller
{
    /** Where the half-finished sign-in is kept between the two steps. */
    private const SESSION_KEY = 'login.otp';

    public function __construct(private readonly EmailVerificationService $service) {}

    /** Whether this site asks for a code at sign-in at all. */
    public static function isRequired(): bool
    {
        return (bool) config('visitdasol.otp.require_on_login');
    }

    /**
     * Hand a correct password over to the code step.
     *
     * Called by LoginController once the password and the account's standing
     * have both been checked, and never from a route: there is no way to
     * reach the code screen without first getting the password right.
     *
     * A MAIL FAILURE MUST NOT LOOK LIKE A WRONG PASSWORD. If the code cannot
     * be sent the person is told plainly, because the alternative is a login
     * screen that silently refuses a password its owner knows is correct.
     */
    public static function begin(Request $request, User $user): RedirectResponse
    {
        $service = app(EmailVerificationService::class);

        try {
            $service->send($user, OtpPurpose::Login);
        } catch (Throwable $e) {
            Log::error('Could not send a sign-in code', [
                'user' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return back()->onlyInput('email')->with(
                'error',
                'Your password was right, but the code could not be emailed to you just now. '
                .'Please try again in a moment, or contact the tourism office.'
            );
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => $request->boolean('remember'),
            'started_at' => now()->timestamp,
        ]);

        return redirect()->route('login.otp');
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->restart();
        }

        $code = $this->service->outstanding($user, OtpPurpose::Login);

        // The screen was reopened after the code expired on its own.
        if ($code === null) {
            $this->service->send($user, OtpPurpose::Login);
            $code = $this->service->outstanding($user, OtpPurpose::Login);
        }

        return view('auth.login-otp', [
            'email' => $user->email,
            'secondsUntilResend' => $code?->secondsUntilResend() ?? 0,
            'secondsUntilExpiry' => $code?->secondsUntilExpiry() ?? 0,
            'attemptsLeft' => $code?->attemptsLeft() ?? (int) config('visitdasol.otp.max_attempts'),
        ]);
    }

    /**
     * The code is right: sign in, and only now.
     */
    public function verify(VerifyEmailCodeRequest $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->restart();
        }

        $result = $this->service->verify($user, $request->validated('code'), OtpPurpose::Login);

        if (! $result->isVerified()) {
            $message = $result->message();

            if ($result === VerificationResult::Incorrect) {
                $left = $this->service->outstanding($user, OtpPurpose::Login)?->attemptsLeft() ?? 0;

                $message .= ' '.($left === 1
                    ? 'One more wrong code and you will need a new one.'
                    : "You have {$left} tries left before you need a new code.");
            }

            return back()->withErrors(['code' => $message])->with('error', $message);
        }

        $pending = (array) $request->session()->pull(self::SESSION_KEY, []);

        /*
         * The account's standing is asked again, not trusted from a minute
         * ago: the office may have suspended it between the password and the
         * code, and the code must not be a way past that.
         */
        if (! $user->isActive()) {
            return $this->restart('That account cannot sign in at the moment.');
        }

        Auth::login($user, (bool) ($pending['remember'] ?? false));

        // A new privilege level begins here, so the session id must not be
        // one an attacker could have planted beforehand.
        $request->session()->regenerate();

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        return redirect()->intended(route('dashboard'))
            ->with('success', "Welcome back, {$user->first_name}.");
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return $this->restart();
        }

        $wait = $this->service->secondsUntilResend($user, OtpPurpose::Login);

        if ($wait > 0) {
            return back()->with('warning', "Please wait {$wait} more seconds before asking for another code.");
        }

        $this->service->send($user, OtpPurpose::Login);

        return back()->with('success', "A new code is on its way to {$user->email}.");
    }

    /** Abandon the half-finished sign-in and go back to the password screen. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('login');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The account waiting on a code, or null when there is none to wait for.
     *
     * Returns null — and so sends the person back to the password screen —
     * when the session holds nothing, when the window has run out, or when
     * the account has been deleted since. Each of those is somebody who is
     * not part-way through signing in, however they arrived here.
     */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['user_id'], $pending['started_at'])) {
            return null;
        }

        $window = (int) config('visitdasol.otp.login_window_minutes');

        if (now()->timestamp - (int) $pending['started_at'] > $window * 60) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return User::query()->whereKey($pending['user_id'])->first();
    }

    private function restart(?string $message = null): RedirectResponse
    {
        return redirect()->route('login')->with(
            'warning',
            $message ?? 'That sign-in took too long. Please enter your password again.'
        );
    }
}
