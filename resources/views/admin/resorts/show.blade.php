@extends('layouts.admin')

@section('title', $resort->name)

@section('content')
    <x-page-header :title="$resort->name" :subtitle="$resort->full_address">
        <a class="btn btn-outline-primary" href="{{ route('admin.resorts.edit', $resort) }}">Edit details</a>
        @if ($resort->isPubliclyVisible())
            <x-external-link class="btn btn-outline-primary" :href="route('resorts.show', $resort)">View public page</x-external-link>
        @endif
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <h2 class="h5 mb-0">Listing</h2>
                    <div class="text-end">
                        <x-status-badge :status="$resort->status" />
                        @unless ($resort->isPubliclyVisible())
                            <span class="d-block small text-muted-vd mt-1">
                                {{ $resort->owner?->isActive() ? 'Not visible to visitors' : 'Hidden because the owner account is not active' }}
                            </span>
                        @endunless
                    </div>
                </div>

                <img class="vd-gallery__main mb-3" src="{{ $resort->main_image_url }}" alt="">
                <p style="white-space: pre-line">{{ $resort->description }}</p>

                <dl class="row mb-0 small border-top pt-3">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Owner</dt>
                    <dd class="col-sm-8"><a href="{{ route('admin.users.show', $resort->owner) }}">{{ $resort->owner?->full_name }}</a></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact</dt>
                    <dd class="col-sm-8">{{ $resort->contact_number }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Rooms</dt>
                    <dd class="col-sm-8">{{ $resort->rooms->count() }} room {{ Str::plural('type', $resort->rooms->count()) }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Map pin</dt>
                    <dd class="col-sm-8 mb-0">{{ $resort->latitude }}, {{ $resort->longitude }}</dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">History</h2>
                @if ($history->isEmpty())
                    <p class="text-muted-vd mb-0">Nothing recorded yet.</p>
                @else
                    <ul class="list-unstyled mb-0 small">
                        @foreach ($history as $entry)
                            <li class="border-top py-2">
                                <span class="d-block">{{ $entry->description }}</span>
                                <span class="text-muted-vd">
                                    {{ $entry->created_at->format('M j, Y g:i A') }}
                                    @if ($entry->user) by {{ $entry->user->full_name }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Publishing</h2>

                @if ($resort->status === \App\Enums\ResortStatus::Pending)
                    <p class="text-muted-vd">This listing is waiting for review.</p>
                    <a class="btn btn-primary" href="{{ route('admin.resort-applications.show', $resort) }}">Review it now</a>
                @elseif ($resort->status === \App\Enums\ResortStatus::Rejected)
                    <p class="text-muted-vd">Sent back to the owner. They can fix it and submit again.</p>
                    @if (filled($resort->review_remarks))
                        <p class="small">Your notes: {{ $resort->review_remarks }}</p>
                    @endif
                @else
                    <div class="d-flex flex-wrap gap-2">
                        @can('deactivate', $resort)
                            <x-confirm-form id="deactivate-resort"
                                            :action="route('admin.resorts.deactivate', $resort)"
                                            title="Take this listing offline?"
                                            message="Visitors will no longer see it and no new bookings can be made. Existing reservations are not changed."
                                            trigger="Take offline"
                                            confirm="Yes, take it offline"
                                            icon="eye-slash"
                                            variant="danger"
                                            trigger-class="btn btn-outline-danger">
                                <div class="mt-3">
                                    <label class="form-label" for="review_remarks">Reason for the owner</label>
                                    <textarea class="form-control @error('review_remarks') is-invalid @enderror"
                                              id="review_remarks" name="review_remarks" rows="3" required>{{ old('review_remarks') }}</textarea>
                                    @error('review_remarks')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </x-confirm-form>
                        @endcan

                        @can('activate', $resort)
                            <x-confirm-form id="activate-resort"
                                            :action="route('admin.resorts.activate', $resort)"
                                            title="Put this listing back online?"
                                            message="Visitors will be able to see and book it again."
                                            trigger="Put back online"
                                            confirm="Yes, publish it"
                                            icon="eye"
                                            variant="primary" />
                        @endcan

                        @can('feature', $resort)
                            <form method="POST" action="{{ route('admin.resorts.feature', $resort) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-light border">
                                    <i class="bi bi-star{{ $resort->is_featured ? '-fill' : '' }} me-1" aria-hidden="true"></i>
                                    {{ $resort->is_featured ? 'Remove from featured' : 'Feature on the home page' }}
                                </button>
                            </form>
                        @endcan
                    </div>
                @endif
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-2">Location</h2>
                <x-location-map :locate="false" :latitude="$resort->latitude" :longitude="$resort->longitude" :label="$resort->name"
                                style="height: 14rem" />
            </section>
        </div>
    </div>
@endsection
