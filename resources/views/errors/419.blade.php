@extends('errors.layout')

@section('code', '419')
@section('title', 'This form expired')
@section('message', 'For your security, forms expire after a while. Go back, reload the page, and submit it again.')

@section('actions')
    @php
        // The previous address comes from the browser's Referer header, which
        // another site can set. Only link back to it if it is on this site.
        $previous = url()->previous();
        $back = str_starts_with($previous, url('/')) ? $previous : url('/');
    @endphp
    <a class="btn btn-primary" href="{{ $back }}">Go back</a>
    <a class="btn btn-outline-primary" href="{{ url('/') }}">Go to the home page</a>
@endsection
