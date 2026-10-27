{{--
    Resort owner dashboard layout.
    Pages use:  @extends('layouts.owner')
--}}
@extends('layouts.dashboard')

@section('area_label', 'Resort owner')

@section('sidebar')
    @include('partials.sidebar-owner')
@endsection
