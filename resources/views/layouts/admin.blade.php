{{--
    Municipal Tourism Office (super admin) layout.
    Pages use:  @extends('layouts.admin')

    The sidebar badges and the top bar's review bell both show
    $pendingOwnerCount and $pendingResortCount, supplied to these two partials
    by the view composer in AppServiceProvider.
--}}
@extends('layouts.dashboard')

@section('area_label', 'Municipal Tourism Office')

@section('topbar')
    @include('partials.topbar-admin')
@endsection

@section('sidebar')
    @include('partials.sidebar-admin')
@endsection
