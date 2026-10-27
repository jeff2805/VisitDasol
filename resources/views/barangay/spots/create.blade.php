@extends('layouts.barangay')

@section('title', 'Add a destination')

@section('content')
    <x-page-header title="Add a destination"
                   subtitle="Nothing is published yet. The tourism office reads it first.">
        <a class="btn btn-light border" href="{{ route('barangay.spots.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('barangay.spots.store') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf

        @include('barangay.spots._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-send" aria-hidden="true"></i> Send to the tourism office
            </button>
            <a class="btn btn-light border" href="{{ route('barangay.spots.index') }}">Cancel</a>
        </div>
    </form>
@endsection
