@extends('layouts.auth')

@section('title', 'Choose a new password')
@section('panel_title', 'Almost there')
@section('panel_text', 'Choose a new password. Once it is saved, any other device signed in to your account is signed out.')

@section('content')
    <h1 class="h2 mb-1">Choose a new password</h1>
    <p class="text-muted-vd mb-4">At least 8 characters, including letters and numbers.</p>

    <form method="POST" action="{{ route('password.store') }}" novalidate data-submit-once>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-form.input name="email" type="email" label="Email address" :value="$email" required autocomplete="email" />
        <x-form.input name="password" type="password" label="New password" required autofocus autocomplete="new-password" />
        <x-form.input name="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password" />

        <button type="submit" class="btn btn-primary btn-lg w-100">Save new password</button>
    </form>

    <hr class="my-4">
    <p class="mb-0">Link not working? <a href="{{ route('password.request') }}">Ask for a new one</a></p>
@endsection
