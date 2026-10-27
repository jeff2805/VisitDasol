<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Setting a new password from an emailed link.
 *
 * The token is single-use and expires after 60 minutes (config/auth.php).
 * After a reset, every other session of that account is signed out and
 * "remember me" cookies stop working, in case the old password had leaked.
 *
 * The person is NOT signed in automatically: they sign in normally, so a
 * pending or deactivated account is still refused at the door.
 */
class NewPasswordController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->password = $password;
                $user->setRememberToken(Str::random(60));
                $user->save();

                $this->endSessions($user);

                event(new PasswordReset($user));

                $this->logger->log('user.password_reset', $user, "Reset the password of {$user->full_name} by email link", [], $user);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('success', 'Your password has been changed. Sign in with your new password.');
        }

        // An invalid or expired link, or an email that does not match it.
        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'This reset link is invalid or has expired. Please ask for a new one.']);
    }

    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
