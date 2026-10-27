@extends('errors.layout')

@section('code', '404')
@section('title', 'Page not found')
@section('message', 'The address may be mistyped, or the listing may no longer be published.')

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the home page</a>
    @if (Route::has('resorts.index'))
        <a class="btn btn-outline-primary" href="{{ route('resorts.index') }}">Browse rooms</a>
    @endif
@endsection
