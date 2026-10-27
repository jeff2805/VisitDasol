@extends('layouts.auth')

@section('title', 'Confirm your email address')
@section('panel_title', 'One last step')
@section('panel_text', 'Confirming your email address keeps your bookings safe and lets resorts reach you about a reservation.')

@section('content')
    <h1 class="h2 mb-1">Check your email</h1>
    <p class="text-muted-vd mb-3">
        We sent a {{ config('visitdasol.otp.length') }}-digit code to
        <strong class="text-break">{{ $email }}</strong>.
    </p>

    {{-- Ticks down once a second (see data-countdown in app.js). The value
         printed here is what someone without JavaScript sees: right at the
         moment the page was built, and honest about being a single reading. --}}
    <p class="vd-otp-clock mb-4" data-countdown="{{ $secondsUntilExpiry }}" data-countdown-format="clock">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <span data-countdown-waiting @if ($secondsUntilExpiry <= 0) hidden @endif>
            This code stops working in
            <strong data-countdown-left>{{ intdiv($secondsUntilExpiry, 60) }}:{{ str_pad((string) ($secondsUntilExpiry % 60), 2, '0', STR_PAD_LEFT) }}</strong>.
        </span>
        <span data-countdown-done @if ($secondsUntilExpiry > 0) hidden @endif>
            This code has expired. Ask for a new one below.
        </span>
    </p>

    @if (session()->has('development_code'))
        {{-- Local development only: there is no mail server, so the code is
             shown here instead of only being written to the log. --}}
        <div class="alert alert-info d-flex align-items-start gap-2" role="status">
            <i class="bi bi-tools mt-1" aria-hidden="true"></i>
            <div>
                <strong>Development only.</strong>
                Email is going to <code>storage/logs/laravel.log</code>, so here is the code:
                <span class="fs-4 fw-bold d-block mt-1" style="letter-spacing:.3em">{{ session('development_code') }}</span>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('verification.verify') }}" novalidate data-submit-once>
        @csrf

        <x-form.input name="code"
                      label="Confirmation code"
                      required
                      autofocus
                      inputmode="numeric"
                      autocomplete="one-time-code"
                      {{-- Room for the separators the request strips, so a code
                           pasted as "123 456" is not cut short as it is typed. --}}
                      maxlength="{{ config('visitdasol.otp.length') + 5 }}"
                      class="form-control form-control-lg text-center"
                      style="letter-spacing:.5em"
                      help="Type the code from the email. Spaces and dashes are ignored." />

        {{-- Only once a try has been used: saying "5 tries left" to someone who
             has not typed anything yet reads as a threat, not as help. --}}
        @if ($attemptsLeft < config('visitdasol.otp.max_attempts'))
            <p @class(['small', 'mb-3', 'd-flex', 'align-items-baseline', 'gap-1',
                       'text-danger' => $attemptsLeft <= 1, 'text-muted-vd' => $attemptsLeft > 1])>
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <span>
                    {{ $attemptsLeft }} {{ Str::plural('try', $attemptsLeft) }} left before this code is
                    cancelled and you need a new one.
                </span>
            </p>
        @endif

        <button type="submit" class="btn btn-primary btn-lg w-100">Confirm my email</button>
    </form>

    <hr class="my-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <p class="mb-1 fw-semibold">Did not get the email?</p>

            {{-- The button is released by the same countdown that writes this
                 sentence, so the two can never disagree. The server checks the
                 cooldown again when the form is sent. --}}
            <p class="text-muted-vd small mb-0"
               data-countdown="{{ $secondsUntilResend }}"
               data-countdown-enables="resend-code">
                Check the spam folder first.
                <span data-countdown-waiting @if ($secondsUntilResend <= 0) hidden @endif>
                    You can ask for another code in
                    <span data-countdown-left>{{ $secondsUntilResend }} {{ Str::plural('second', $secondsUntilResend) }}</span>.
                </span>
                <span data-countdown-done @if ($secondsUntilResend > 0) hidden @endif>
                    You can ask for another one now.
                </span>
            </p>
        </div>

        <form method="POST" action="{{ route('verification.resend') }}">
            @csrf
            <button type="submit" id="resend-code" class="btn btn-outline-secondary" @disabled($secondsUntilResend > 0)>
                Send another code
            </button>
        </form>
    </div>

    {{-- The profile page stays reachable without confirming, so a mistyped
         address can still be corrected. Saving a new address clears the
         confirmation and brings the person back here. --}}
    <div class="text-muted-vd small mt-4 d-flex flex-wrap align-items-baseline gap-1">
        <span>Wrong address?</span>
        <a href="{{ route('profile.edit') }}">Correct it in your profile</a>
        <span>or</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">sign out</button>
        </form>
        <span>and start again.</span>
    </div>
@endsection
