@extends('layouts.barangay')

@section('title', 'Edit '.$spot->name)

@section('content')
    <x-page-header :title="$spot->name" :subtitle="'Barangay '.$spot->barangay">
        <x-status-badge :status="$spot->status" />
        <a class="btn btn-light border" href="{{ route('barangay.spots.index') }}">Back</a>
    </x-page-header>

    {{-- What the office asked for. Shown before the form, because it is the
         reason this page is open at all. --}}
    @if ($sentBack)
        <div class="alert alert-warning" role="status">
            <strong class="d-block mb-1">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                The tourism office sent this back
            </strong>
            {{ filled($spot->review_remarks) ? $spot->review_remarks : 'No reason was given.' }}
            <span class="d-block small mt-2">
                Make the changes below, save, then send it again.
            </span>
        </div>
    @endif

    <form method="POST" action="{{ route('barangay.spots.update', $spot) }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('barangay.spots._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('barangay.spots.index') }}">Cancel</a>
        </div>
    </form>

    @if ($sentBack)
        <form class="mt-3" method="POST" action="{{ route('barangay.spots.resubmit', $spot) }}" data-submit-once>
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-accent btn-lg">
                <i class="bi bi-send" aria-hidden="true"></i> Send to the office again
            </button>
            <span class="form-text d-block mt-1">Save your changes first, then send.</span>
        </form>
    @endif
@endsection
