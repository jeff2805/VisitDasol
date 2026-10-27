@extends('layouts.auth')

@section('title', 'Forgot your password')
@section('panel_title', 'Locked out?')
@section('panel_text', 'Enter the email you registered with and we will send you a link to choose a new password.')

@section('content')
    <h1 class="h2 mb-1">Forgot your password?</h1>
    <p class="text-muted-vd mb-4">We will email you a link to choose a new one. The link works for 60 minutes.</p>

    <form method="POST" action="{{ route('password.email') }}" novalidate data-submit-once>
        @csrf

        <x-form.input name="email" type="email" label="Email address" required autofocus autocomplete="email" />

        <button type="submit" class="btn btn-primary btn-lg w-100">Email me a reset link</button>
    </form>

    <hr class="my-4">
    <p class="mb-0">Remembered it? <a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
