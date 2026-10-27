@extends('layouts.admin')

@section('title', 'Edit resort')

@section('content')
    <x-page-header title="Edit listing" :subtitle="$resort->name">
        <a class="btn btn-light border" href="{{ route('admin.resorts.show', $resort) }}">Cancel</a>
    </x-page-header>

    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            Corrections you make here are recorded and the owner keeps managing the listing.
            This screen never changes the listing's status: use the publishing actions for that.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.resorts.update', $resort) }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('owner.resort._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('admin.resorts.show', $resort) }}">Cancel</a>
        </div>
    </form>
@endsection
