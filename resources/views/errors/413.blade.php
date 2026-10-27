@extends('errors.layout')

@section('code', '413')
@section('title', 'That upload was too large')
@section('message', 'Photos can be up to 3 MB each and permit documents up to 5 MB. Try a smaller file, or send fewer photos at a time.')

@section('actions')
    @php
        $previous = url()->previous();
        $back = str_starts_with($previous, url('/')) ? $previous : url('/');
    @endphp
    <a class="btn btn-primary" href="{{ $back }}">Go back</a>
@endsection
