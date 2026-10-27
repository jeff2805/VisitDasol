@extends('layouts.admin')

@section('title', 'Write an announcement')

@section('content')
    <x-page-header title="Write an announcement" subtitle="It is saved as a draft, so nothing appears to visitors until you publish it.">
        <a class="btn btn-light border" href="{{ route('admin.announcements.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.announcements.store') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf

        @include('admin.announcements._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save as draft</button>
            <a class="btn btn-light border" href="{{ route('admin.announcements.index') }}">Cancel</a>
        </div>
    </form>
@endsection
