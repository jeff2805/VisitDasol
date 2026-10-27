@extends('layouts.owner')

@section('title', 'Add room type')

@section('content')
    <x-page-header title="Add a room type" :subtitle="$resort->name">
        <a class="btn btn-light border" href="{{ route('owner.rooms.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('owner.rooms.store') }}" novalidate data-submit-once>
        @csrf

        @include('owner.rooms._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Add room type</button>
            <a class="btn btn-light border" href="{{ route('owner.rooms.index') }}">Cancel</a>
        </div>
    </form>
@endsection
