@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong on our side')
@section('message', 'The page couldn’t be loaded. Try again in a few minutes. If it keeps happening, contact the Municipal Tourism Office.')

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
