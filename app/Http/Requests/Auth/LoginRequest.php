<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks email and password. Three wrong passwords shut the door for
 * thirty minutes, both figures settable by the tourism office.
 * Tracks attempts per email and IP address together.
 * Does NOT sign the user in; the controller first checks account status.
 */
class LoginRequest extends FormRequest
{
    /**
     * Wrong passwords allowed before the door shuts.
     *
     * Read from configuration rather than written here, because the
     * settings screen offers the office a box for it — and while this was
     * a constant, that box wrote to the database and nothing read it
     * back. A setting that does nothing is worse than no setting: it says
     * the system behaves a way it does not.
     */
    private function maxAttempts(): int
    {
        return max(1, (int) config('visitdasol.auth.login_max_attempts', 3));
    }

    /** How long the door stays shut, in seconds. */
    private function decaySeconds(): int
    {
        return max(60, (int) config('visitdasol.auth.lockout_minutes', 30) * 60);
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => Str::lower(trim($this->input('email'))),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Returns the matching user when the password is correct.
     *
     * @throws ValidationException
     */
    public function verifiedUser(): User
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::validate($this->only('email', 'password'))) {
            RateLimiter::hit(
                $this->throttleKey(),
                $this->decaySeconds()
            );

            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        $user = Auth::getLastAttempted();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts(
            $this->throttleKey(),
            $this->maxAttempts()
        )) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        /*
         * Said in whichever unit a person would use. A thirty-minute
         * lockout reported as "1800 seconds" is a number somebody has to
         * do arithmetic on while already locked out and annoyed.
         */
        $wait = $seconds >= 60
            ? ceil($seconds / 60).' '.Str::plural('minute', (int) ceil($seconds / 60))
            : $seconds.' '.Str::plural('second', $seconds);

        throw ValidationException::withMessages([
            'email' => "Too many sign-in attempts. Please try again in {$wait}.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower((string) $this->input('email')) . '|' . $this->ip()
        );
    }
}
