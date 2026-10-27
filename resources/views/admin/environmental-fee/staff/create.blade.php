@extends('layouts.admin')

@section('title', 'Add fee staff')

@section('content')
    <x-page-header title="Add fee staff"
                   subtitle="Create an account for somebody who works the environmental fee.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.staff.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.environmental-fee.staff.store') }}" data-submit-once>
        @csrf

        @include('admin.environmental-fee.staff._form')

        <div class="mt-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i>Create and send invitation
            </button>
            <a class="btn btn-light border" href="{{ route('admin.environmental-fee.staff.index') }}">Cancel</a>
        </div>

        <p class="form-text mt-2">
            They will be emailed a link to set their own password. It expires in
            {{ config('auth.passwords.users.expire', 60) }} minutes and can be used once.
        </p>
    </form>
@endsection
