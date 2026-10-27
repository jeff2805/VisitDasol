@extends('layouts.auth')

@section('title', 'Create a tourist account')
@section('panel_title', 'Plan your stay in Dasol ')
@section('panel_text', 'A free account lets you request rooms at approved resorts and keep track of your reservations.')

@section('content')
    <h1 class="h2 mb-1">Create your account</h1>
    <p class="text-muted-vd mb-4">Already registered? <a href="{{ route('login') }}">Sign in instead</a>.</p>

    <form method="POST" action="{{ route('register') }}" novalidate data-submit-once>
        @csrf

        <div class="row">
            <div class="col-sm-6">
                <x-form.input name="first_name" label="First name" required autofocus autocomplete="given-name" />
            </div>
            <div class="col-sm-6">
                <x-form.input name="last_name" label="Last name" required autocomplete="family-name" />
            </div>
        </div>

        <x-form.input name="email" type="email" label="Email address" required autocomplete="email" />
        <x-form.input name="phone" type="tel" label="Mobile number" required autocomplete="tel"
                      help="Resorts use this to reach you about a reservation." />

        <div class="row">
            <div class="col-sm-7">
                <x-form.input name="home_city" label="City or municipality" required autocomplete="address-level2" />
            </div>
            <div class="col-sm-5">
                <x-form.input name="home_country" label="Country" required autocomplete="country-name" />
            </div>
        </div>
        <p class="form-text mt-0 mb-3">Where you are travelling from. The tourism office uses this for visitor statistics only.</p>

        <x-form.input name="password" type="password" label="Password" required autocomplete="new-password"
                      help="At least 8 characters, including letters and numbers." />
        <x-form.input name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password" />

        {{-- The box now actually stands for something: it names the two
             documents the person is agreeing to, and links them. --}}
        <x-form.checkbox name="terms" required>
            I confirm the details above are correct, and I agree to the
            <x-external-link :href="route('terms')">terms of use</x-external-link>
            and the <x-external-link :href="route('privacy')">privacy notice</x-external-link>.
        </x-form.checkbox>

        <button type="submit" class="btn btn-primary btn-lg w-100">Create account</button>
    </form>

    {{-- Tourist accounts only, which is exactly what this page makes. The
         owner application page deliberately has none: that account is
         approved by the office before it works. --}}
    <x-social-buttons verb="Sign up" />
@endsection
