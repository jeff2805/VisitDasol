{{-- Local-only preview of the MTO layout. Sample content only; nothing here is saved. --}}
@extends('layouts.admin')

@section('title', 'MTO dashboard preview')

@section('content')
    <x-page-header title="Tourism office dashboard" subtitle="Layout preview with sample numbers. Live statistics arrive in Phase 15." />

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="vd-panel p-3 h-100 d-flex align-items-center gap-3">
                <span class="vd-feature__icon"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                <div class="flex-grow-1">
                    <div class="vd-stat__value">{{ $pendingOwnerCount }}</div>
                    <div class="vd-stat__label">Owner applications to review</div>
                </div>
                <span class="btn btn-primary disabled" aria-disabled="true">Review</span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="vd-panel p-3 h-100 d-flex align-items-center gap-3">
                <span class="vd-feature__icon"><i class="bi bi-building-check" aria-hidden="true"></i></span>
                <div class="flex-grow-1">
                    <div class="vd-stat__value">{{ $pendingResortCount }}</div>
                    <div class="vd-stat__label">Resort applications to review</div>
                </div>
                <span class="btn btn-primary disabled" aria-disabled="true">Review</span>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([['128', 'Tourists'], ['9', 'Active resort owners'], ['7', 'Active resorts'], ['41', 'Room types']] as [$value, $label])
            <div class="col-6 col-xl-3">
                <x-stat :value="$value" :label="$label" />
            </div>
        @endforeach
    </div>

    <section class="vd-panel p-3 mb-4">
        <h2 class="h5">Status badges</h2>
        <div class="d-flex flex-wrap gap-2">
            @foreach (['pending', 'active', 'rejected', 'inactive', 'confirmed', 'cancelled', 'completed', 'draft', 'published'] as $status)
                <span class="vd-badge vd-badge--{{ $status }}">{{ ucfirst($status) }}</span>
            @endforeach
        </div>
    </section>

    <section class="vd-panel p-3">
        <h2 class="h5">Messages</h2>
        <div class="alert alert-success d-flex gap-2" role="status"><i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i><div>Owner account approved. The owner can now sign in.</div></div>
        <div class="alert alert-info d-flex gap-2" role="status"><i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i><div>Rejected applicants see your remarks when they try to sign in.</div></div>
        <div class="alert alert-danger d-flex gap-2 mb-0" role="alert"><i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i><div>This resort can't be approved yet: add a cover photo first.</div></div>
    </section>
@endsection
