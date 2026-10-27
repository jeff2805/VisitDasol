@extends('errors.layout')

@section('code', '403')
@section('title', 'You can’t open this page')
@section('message', 'Your account doesn’t have access to this area. If you think it should, contact the Municipal Tourism Office.')

@section('actions')
    @if (Route::has('dashboard') && auth()->check())
        <a class="btn btn-primary" href="{{ route('dashboard') }}">Go to my dashboard</a>
    @endif
    <a class="btn btn-outline-primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
