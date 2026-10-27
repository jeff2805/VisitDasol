@extends('layouts.admin')

@section('title', 'Review application')

@php($profile = $owner->ownerProfile)

@section('content')
    <x-page-header :title="$owner->full_name" :subtitle="$profile?->business_name">
        <a class="btn btn-outline-primary" href="{{ route('admin.owner-applications.index') }}">Back to applications</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Applicant</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Name</dt>
                    <dd class="col-sm-8">{{ $owner->full_name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Email</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $owner->email }}">{{ $owner->email }}</a></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-sm-8">{{ $owner->phone ?? '—' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Applied on</dt>
                    <dd class="col-sm-8">{{ $owner->created_at->format('M j, Y g:i A') }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Status</dt>
                    <dd class="col-sm-8 mb-0"><x-status-badge :status="$owner->status" /></dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Business</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Resort or business</dt>
                    <dd class="col-sm-8">{{ $profile?->business_name ?? '—' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Position</dt>
                    <dd class="col-sm-8">{{ $profile?->position ?? '—' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Permit number</dt>
                    <dd class="col-sm-8">{{ $profile?->business_permit_no ?? 'Not provided' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Permit document</dt>
                    <dd class="col-sm-8 mb-0">
                        @if ($profile?->hasPermitDocument())
                            <x-external-link class="btn btn-sm btn-outline-primary" :href="route('admin.owner-applications.permit', $owner)">
                                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Open document
                            </x-external-link>
                            <span class="d-block small text-muted-vd mt-1">Only the tourism office can open this file.</span>
                        @else
                            <span class="text-muted-vd">Not provided</span>
                        @endif
                    </dd>
                </dl>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Decision</h2>

                @if ($owner->status === \App\Enums\UserStatus::Pending)
                    <p class="text-muted-vd">
                        Approving lets this person sign in and submit their resort. The resort itself
                        is reviewed separately before it appears publicly.
                    </p>

                    <div class="d-flex flex-wrap gap-2">
                        <x-confirm-form id="approve-owner"
                                        :action="route('admin.owner-applications.approve', $owner)"
                                        title="Approve this owner account?"
                                        :message="$owner->full_name.' will be able to sign in and submit a resort for review.'"
                                        trigger="Approve application"
                                        confirm="Yes, approve"
                                        icon="check-lg"
                                        variant="primary" />

                        <x-confirm-form id="reject-owner"
                                        :action="route('admin.owner-applications.reject', $owner)"
                                        title="Turn down this application?"
                                        message="The applicant sees your reason when they try to sign in."
                                        trigger="Turn down"
                                        confirm="Turn down application"
                                        icon="x-lg"
                                        variant="danger"
                                        trigger-class="btn btn-outline-danger">
                            <div class="mt-3">
                                <label class="form-label" for="review_remarks">Reason</label>
                                <textarea class="form-control @error('review_remarks') is-invalid @enderror"
                                          id="review_remarks" name="review_remarks" rows="3" required
                                          placeholder="For example: the permit number could not be verified.">{{ old('review_remarks') }}</textarea>
                                @error('review_remarks')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </x-confirm-form>
                    </div>
                @elseif ($owner->status === \App\Enums\UserStatus::Rejected)
                    <div class="alert alert-danger" role="status">
                        <strong class="d-block">Turned down on {{ $profile?->reviewed_at?->format('M j, Y') }}</strong>
                        {{ $profile?->review_remarks }}
                    </div>

                    <x-confirm-form id="reopen-owner"
                                    :action="route('admin.owner-applications.reopen', $owner)"
                                    title="Review this application again?"
                                    message="It goes back to the waiting list so you can approve or turn it down again."
                                    trigger="Review again"
                                    confirm="Yes, reopen"
                                    icon="arrow-counterclockwise"
                                    variant="primary" />
                @else
                    <div class="alert alert-success mb-3" role="status">
                        This account is {{ $owner->status->label() }}.
                        @if ($profile?->reviewed_at)
                            Reviewed on {{ $profile->reviewed_at->format('M j, Y') }}
                            @if ($profile->reviewer) by {{ $profile->reviewer->full_name }} @endif.
                        @endif
                    </div>
                    <a class="btn btn-outline-primary" href="{{ route('admin.users.show', $owner) }}">Manage this account</a>
                @endif
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-2">Their resort</h2>
                @if ($owner->resorts->isEmpty())
                    <p class="text-muted-vd mb-0">No resort submitted yet. Owners can submit one after approval.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach ($owner->resorts as $resort)
                            <li class="d-flex justify-content-between align-items-center gap-2">
                                <span>{{ $resort->name }}</span>
                                <x-status-badge :status="$resort->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
