@extends('layouts.owner')

@section('title', 'Register your resort')

@section('content')
    <x-page-header title="Register your resort"
                   subtitle="The Municipal Tourism Office reviews your details before the listing goes public." />

    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            You can save now and add photos and rooms afterwards. Nothing appears publicly until the
            tourism office approves the listing.
        </div>
    </div>

    <form method="POST" action="{{ route('owner.resort.store') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf

        @include('owner.resort._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Submit for review</button>
            <a class="btn btn-light border" href="{{ route('owner.dashboard') }}">Cancel</a>
        </div>
    </form>
@endsection
