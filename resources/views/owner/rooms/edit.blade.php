@extends('layouts.owner')

@section('title', 'Edit room type')

@section('content')
    <x-page-header title="Edit room type" :subtitle="$room->name">
        <a class="btn btn-light border" href="{{ route('owner.rooms.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('owner.rooms.update', $room) }}" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('owner.rooms._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('owner.rooms.index') }}">Cancel</a>
        </div>
    </form>
@endsection
