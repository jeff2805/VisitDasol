@extends('layouts.auth')

@section('title', 'Apply as a resort owner')
@section('panel_title', 'List your resort in Dasol')
@section('panel_text', 'Resort accounts are reviewed by the Municipal Tourism Office before listings go live.')

@section('content')
    <h1 class="h2 mb-1">Resort owner application</h1>
    <p class="text-muted-vd mb-4">Already approved? <a href="{{ route('login') }}">Sign in instead</a>.</p>

    <div class="vd-panel p-3 mb-4">
        <h2 class="h6 mb-2">How this works</h2>
        <ol class="mb-0 ps-3 text-muted-vd small">
            <li>You send this application.</li>
            <li>The Municipal Tourism Office reviews it.</li>
            <li>Once approved, you can sign in and submit your resort.</li>
            <li>The office reviews the resort, and then it appears publicly.</li>
        </ol>
    </div>

    <form method="POST" action="{{ route('register.owner') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf

        <h2 class="h5 mb-3">Your account</h2>

        <div class="row">
            <div class="col-sm-6">
                <x-form.input name="first_name" label="First name" required autofocus autocomplete="given-name" />
            </div>
            <div class="col-sm-6">
                <x-form.input name="last_name" label="Last name" required autocomplete="family-name" />
            </div>
        </div>

        <x-form.input name="email" type="email" label="Email address" required autocomplete="email" />
        <x-form.input name="phone" type="tel" label="Contact number" required autocomplete="tel"
                      help="The tourism office uses this to reach you about your application." />

        <x-form.input name="password" type="password" label="Password" required autocomplete="new-password"
                      help="At least 8 characters, including letters and numbers." />
        <x-form.input name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password" />

        <h2 class="h5 mt-4 mb-3">Your resort or business</h2>

        <x-form.input name="business_name" label="Resort or business name" required
                      help="You add full resort details, photos, and rooms after your account is approved." />

        <x-form.select name="position" label="Your position" :options="$positions" placeholder="Choose one" required />

        <x-form.input name="business_permit_no" label="Business permit number" />

        <x-form.input name="permit_document" type="file" label="Business permit document"
                      accept=".jpg,.jpeg,.png,.pdf"
                      help="JPG, PNG, or PDF up to 5 MB. Only the tourism office can open it." />

        <x-form.checkbox name="terms" required>
            I own or am authorized to manage this business, the details above are accurate, and I agree
            to the <x-external-link :href="route('terms')">terms of use</x-external-link>
            and the <x-external-link :href="route('privacy')">privacy notice</x-external-link>.
        </x-form.checkbox>

        <button type="submit" class="btn btn-primary btn-lg w-100">Send application</button>
    </form>
@endsection
