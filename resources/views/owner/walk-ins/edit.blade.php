@extends('layouts.owner')

@section('title', 'Correct a walk-in')

@section('content')
    <x-page-header :title="'Correct '.$record->booking_reference"
                   subtitle="Change what the tourism office needs, then send it back to them.">
        <a class="btn btn-light border" href="{{ route('owner.walk-ins.show', $record) }}">Back to the record</a>
    </x-page-header>

    {{-- The reason the office sent it back, at the top where it is read
         before anything is changed. --}}
    @if (filled($record->return_remarks))
        <div class="alert alert-warning" role="alert">
            <strong class="d-block">What the tourism office asked for</strong>
            {{ $record->return_remarks }}
        </div>
    @endif

    @error('room')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('owner.walk-ins.update', $record) }}" data-submit-once>
        @include('owner.walk-ins._form', [
            'record' => $record,
            'rooms' => $rooms,
            'origins' => $origins,
            'types' => $types,
        ])

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save the correction
            </button>
            <a class="btn btn-light border btn-lg" href="{{ route('owner.walk-ins.show', $record) }}">Cancel</a>
        </div>

        <p class="form-text mt-2 mb-0">
            The guest's room stays held throughout. Saving does not send the record;
            you send it from the record's own page.
        </p>
    </form>
@endsection
