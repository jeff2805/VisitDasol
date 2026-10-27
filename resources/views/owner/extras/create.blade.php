@extends('layouts.owner')

@section('title', 'Add something you offer')

@section('content')
    <x-page-header title="Add something you offer"
                   subtitle="Guests will be able to ask for this when they book a room.">
        <a class="btn btn-light border" href="{{ route('owner.extras.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('owner.extras.store') }}" novalidate data-submit-once>
        @csrf
        @include('owner.extras._form')

        <div class="mt-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Add it</button>
            <a class="btn btn-light border" href="{{ route('owner.extras.index') }}">Cancel</a>
        </div>
    </form>
@endsection
