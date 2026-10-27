{{--
    Barangay official layout.
    Pages use:  @extends('layouts.barangay')
--}}
@extends('layouts.dashboard')

@section('area_label', 'Barangay official')

@section('sidebar')
    @include('partials.sidebar-barangay')
@endsection
