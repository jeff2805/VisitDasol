@extends('layouts.owner')

@section('title', $extra->name)

@section('content')
    <x-page-header :title="$extra->name" subtitle="Changing the price here does not change what guests were already quoted.">
        <a class="btn btn-light border" href="{{ route('owner.extras.index') }}">Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('owner.extras.update', $extra) }}" novalidate data-submit-once>
        @csrf
        @method('PUT')
        @include('owner.extras._form')

        <div class="mt-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a class="btn btn-light border" href="{{ route('owner.extras.index') }}">Cancel</a>
        </div>

        <p class="form-text mt-2">
            There is no delete. Guests have asked for this by name and their requests
            carry it, so finishing with something means hiding it from the booking form.
        </p>
    </form>
@endsection
