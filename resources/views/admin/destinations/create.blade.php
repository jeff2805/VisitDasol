@extends('layouts.admin')

@section('title', 'Add destination')

@section('content')
    <x-page-header title="Add a destination" subtitle="It is published as soon as you save. You can hide it at any time.">
        <a class="btn btn-light border" href="{{ route('admin.destinations.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.destinations.store') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf

        @include('admin.destinations._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save and publish</button>
            <a class="btn btn-light border" href="{{ route('admin.destinations.index') }}">Cancel</a>
        </div>
    </form>
@endsection
