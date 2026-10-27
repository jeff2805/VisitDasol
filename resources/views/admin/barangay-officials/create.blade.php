@extends('layouts.admin')

@section('title', 'Add a barangay official')

@section('content')
    <x-page-header title="Add a barangay official"
                   subtitle="They can write up destinations for one barangay. You still decide what gets published.">
        <a class="btn btn-light border" href="{{ route('admin.barangay-officials.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.barangay-officials.store') }}" novalidate data-submit-once>
        @csrf

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">Who they are</h2>

                    <div class="row">
                        <div class="col-sm-6">
                            <x-form.input name="first_name" label="First name" required autocomplete="off" />
                        </div>
                        <div class="col-sm-6">
                            <x-form.input name="last_name" label="Last name" required autocomplete="off" />
                        </div>
                    </div>

                    <x-form.select name="barangay" label="Barangay they speak for"
                                   :options="array_combine($barangays, $barangays)"
                                   placeholder="Choose a barangay" required />

                    <x-form.input name="position" label="Position"
                                  help="Optional. E.g. Punong Barangay, Barangay Secretary, Barangay Tourism Officer." />
                </section>

                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">How they sign in</h2>

                    <x-form.input name="email" type="email" label="Email address" required autocomplete="off"
                                  help="They confirm this address with a code the first time they sign in." />

                    <x-form.input name="phone" type="tel" label="Contact number" required autocomplete="off" />

                    <x-form.input name="password" type="password" label="Starting password" required
                                  autocomplete="new-password"
                                  help="Give this to them in person or by phone, not by email. They can change it from their profile." />

                    <x-form.input name="password_confirmation" type="password" label="Confirm the password" required
                                  autocomplete="new-password" />
                </section>
            </div>

            <div class="col-lg-5">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-2">What this account can do</h2>
                    <ul class="mb-3 ps-3 text-muted-vd">
                        <li>Write up destinations in their own barangay, and only there.</li>
                        <li>Send them to this office for approval.</li>
                        <li>Correct and resend anything you send back.</li>
                    </ul>

                    <h2 class="h6 mb-2">What it cannot do</h2>
                    <ul class="mb-0 ps-3 text-muted-vd">
                        <li>Publish anything. Nothing they write is visible to visitors until you approve it.</li>
                        <li>Touch another barangay's destinations, resorts, bookings, or accounts.</li>
                        <li>Edit a spot after you have published it.</li>
                    </ul>
                </section>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Create the account</button>
            <a class="btn btn-light border" href="{{ route('admin.barangay-officials.index') }}">Cancel</a>
        </div>
    </form>
@endsection
