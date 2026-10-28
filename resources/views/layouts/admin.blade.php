{{--
    Municipal Tourism Office (super admin) layout.
    Pages use:  @extends('layouts.admin')

    The sidebar badges and the top bar's review bell both show
    $pendingOwnerCount and $pendingResortCount, supplied to these two partials
    by the view composer in AppServiceProvider.
--}}
@extends('layouts.dashboard')

@section('area_label', auth()->user()?->isSuperAdmin() ? 'Municipal Tourism Office' : auth()->user()?->role->label())

{{--
    A CASHIER REACHES SOME OF THESE PAGES TOO.

    admin/environmental-fee/* is deliberately wider at the door than the
    rest of admin/ — see the route group — because counting handovers and
    reading the municipal figures is a cashier's whole job and both live
    under that prefix. The views there extend this layout, so without
    these two guards a cashier got the full office menu: approvals,
    users, resorts, the activity log, and a search box and review bell
    pointing at screens the policies refuse them. Every one of those
    links was a 403 waiting to happen.
--}}
@section('topbar')
    @if (auth()->user()?->isSuperAdmin())
        @include('partials.topbar-admin')
    @endif
@endsection

@section('sidebar')
    @include('partials.sidebar-for-role')
@endsection
