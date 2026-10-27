@extends('layouts.auth')

@section('title', 'Sign in')
{{-- The place name is picked out in the brand's light teal, the way the
     wordmark picks out "Dasol". Written as a section rather than a string so
     the one word can carry the colour. --}}
{{-- The spaces around the break are deliberate: without them the line is read
     aloud as "Welcome backto Dasol". --}}
@section('panel_title')
    Welcome back <br> to <span class="vd-auth__panel-accent">Dasol</span>
@endsection
@section('panel_text', 'Sign in to manage your reservations, your resort listing, or the tourism office dashboard.')

@section('content')
    <h1 class="h2 mb-1">Sign in</h1>
    {{-- Who is being welcomed, then what to do. The page went from the word
         "Sign in" straight to an instruction, which told a returning guest
         nothing about where they had arrived. --}}
    <p class="vd-auth__welcome mb-1">Welcome back to {{ config('visitdasol.name') }}</p>
    <p class="text-muted-vd mb-4">Use the email address and password you registered with.</p>

    <form method="POST" action="{{ route('login') }}" novalidate data-submit-once>
        @csrf

        <x-form.input name="email" type="email" label="Email address" icon="envelope-fill" required autofocus
                      autocomplete="username" placeholder="you@example.com" />
        <x-form.input name="password" type="password" label="Password" icon="lock-fill" required
                      autocomplete="current-password" placeholder="Enter your password" />

        <p class="text-end small mt-n2 mb-3">
            <a href="{{ route('password.request') }}">Forgot your password?</a>
        </p>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" value="1" name="remember" id="field-remember" @checked(old('remember'))>
            <label class="form-check-label" for="field-remember">Keep me signed in on this device</label>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100 vd-signin">
            Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
    </form>

    <x-social-buttons />

    {{-- The two ways in for somebody who has no account, set apart from the
         form rather than listed under a rule. They were two bare sentences
         after an <hr>, which read as small print belonging to the form above
         instead of an offer of their own. --}}
    <p class="vd-or"><span>or</span></p>

    <div class="vd-auth__new">
        <p class="mb-1">New to Visit Dasol? <a href="{{ route('register') }}">Create a tourist account</a></p>
        <p class="mb-0">Own or manage a resort? <a href="{{ route('register.owner') }}">Apply as a resort owner</a></p>
    </div>
@endsection
