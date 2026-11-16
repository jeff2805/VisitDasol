@extends('layouts.owner')

@section('title', 'Record a walk-in')

@section('content')
    <x-page-header title="Record a walk-in"
                   subtitle="A guest who booked at your gate. Saving holds the room straight away.">
        <a class="btn btn-light border" href="{{ route('owner.walk-ins.index') }}">Back to records</a>
    </x-page-header>

    @if ($rooms->isEmpty())
        <x-empty-state icon="door-open" title="No rooms to book yet"
                       message="Add a room type with its rate and how many of that room you have. A walk-in has to be recorded against a room.">
            <a class="btn btn-primary" href="{{ route('owner.rooms.create') }}">Add a room type</a>
        </x-empty-state>
    @else
        @error('room')
            <div class="alert alert-danger" role="alert">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('owner.walk-ins.store') }}" data-submit-once>
            @include('owner.walk-ins._form', [
                'rooms' => $rooms,
                'origins' => $origins,
                'types' => $types,
            ])

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save me-1" aria-hidden="true"></i>Save as draft
                </button>
                <a class="btn btn-light border btn-lg" href="{{ route('owner.walk-ins.index') }}">Cancel</a>
            </div>

            {{-- Said plainly, because "draft" could be read as "not real yet"
                 and the room really is held from this moment. --}}
            <p class="form-text mt-2 mb-0">
                The room is held as soon as you save. Sending the record to the tourism
                office is a separate step, and you can do it later.
            </p>
        </form>
    @endif
@endsection
