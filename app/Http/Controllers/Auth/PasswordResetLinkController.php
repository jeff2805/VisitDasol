<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

/**
 * "Forgot your password?"
 *
 * The answer is always the same, whether or not the email belongs to an
 * account, so this page cannot be used to find out who is registered.
 * The link is emailed; with MAIL_MAILER=log it is written to
 * storage/logs/laravel.log instead, which is how to test it locally.
 */
class PasswordResetLinkController extends Controller
{
    public const SENT_MESSAGE = 'If that email address belongs to an account, a link to reset the password is on its way. It works for 60 minutes.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        // The broker also limits each address to one email per minute.
        Password::sendResetLink($request->only('email'));

        return back()->with('success', self::SENT_MESSAGE);
    }
}
