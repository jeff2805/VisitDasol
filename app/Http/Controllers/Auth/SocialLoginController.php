<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\SocialLoginException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SocialLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Signing in with Google or Facebook.
 *
 * This controller only moves people about. Every decision about who an
 * account belongs to — and the refusals that stop one person walking into
 * another's reservations — lives in SocialLogin.
 */
class SocialLoginController extends Controller
{
    public function __construct(private readonly SocialLogin $accounts) {}

    /** Off to the provider's own sign-in page. */
    public function redirect(string $provider): RedirectResponse
    {
        $chosen = $this->provider($provider);

        return Socialite::driver($chosen->value)->redirect();
    }

    /**
     * Back from the provider.
     *
     * Nothing in the query string is believed. The code is exchanged with the
     * provider server to server, and the answer that comes back is the only
     * thing used.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $chosen = $this->provider($provider);

        // The visitor pressed cancel, or refused a permission.
        if ($request->filled('error')) {
            return redirect()->route('login')
                ->with('info', 'No harm done. You can sign in with your email address instead.');
        }

        try {
            $account = Socialite::driver($chosen->value)->user();
        } catch (Throwable $e) {
            Log::warning('Social sign-in failed at the provider', [
                'provider' => $chosen->value,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('login')
                ->with('error', $chosen->label().' could not sign you in just now. Please try again, '
                    .'or use your email address and password.');
        }

        try {
            $user = $this->accounts->resolve($chosen, $account);
        } catch (SocialLoginException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * A provider that is both known and actually configured.
     *
     * Unconfigured is a 404, not an error page: without keys the buttons are
     * not on the page at all, so anyone reaching this address typed it.
     */
    private function provider(string $provider): SocialProvider
    {
        $chosen = SocialProvider::tryFrom($provider);

        abort_if($chosen === null || ! $chosen->isConfigured(), 404);

        return $chosen;
    }
}
