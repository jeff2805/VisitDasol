@extends('layouts.auth')

@section('title', 'Application received')
@section('panel_title', 'Thank you')
@section('panel_text', 'The Municipal Tourism Office reviews every resort owner application before an account is activated.')

@section('content')
    <div class="text-center mb-4">
        <span class="vd-empty__icon"><i class="bi bi-send-check" aria-hidden="true"></i></span>
        <h1 class="h2 mt-2 mb-2">Application received</h1>
        <p class="text-muted-vd mb-0">Your resort owner account has been created and is waiting for review.</p>
    </div>

    <div class="vd-panel p-3 mb-4">
        <h2 class="h6 mb-2">What happens next</h2>
        <ul class="mb-0 ps-3 text-muted-vd small">
            <li>The Municipal Tourism Office checks your details.</li>
            <li>You cannot sign in until the account is approved.</li>
            <li>Try signing in later: once approved, you will reach your owner dashboard.</li>
            <li>If the application is turned down, the reason appears when you try to sign in.</li>
        </ul>
    </div>

    <div class="d-grid gap-2">
        <a class="btn btn-primary" href="{{ route('login') }}">Go to sign in</a>
        <a class="btn btn-outline-primary" href="{{ route('home') }}">Back to Visit Dasol</a>
    </div>
@endsection
