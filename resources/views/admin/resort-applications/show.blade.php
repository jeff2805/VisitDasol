@extends('layouts.admin')

@section('title', 'Review listing')

@section('content')
    <x-page-header :title="$resort->name" :subtitle="$resort->full_address">
        <a class="btn btn-outline-primary" href="{{ route('admin.resort-applications.index') }}">Back to applications</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Listing</h2>

                <img class="vd-gallery__main mb-3" src="{{ $resort->main_image_url }}" alt="">

                @if ($resort->images->isNotEmpty())
                    <ul class="vd-gallery__strip mb-3">
                        @foreach ($resort->images as $image)
                            <li><img src="{{ $image->url }}" alt="{{ $image->caption }}"></li>
                        @endforeach
                    </ul>
                @endif

                <p style="white-space: pre-line">{{ $resort->description }}</p>

                <dl class="row mb-0 small border-top pt-3">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Address</dt>
                    <dd class="col-sm-8">{{ $resort->full_address }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact</dt>
                    <dd class="col-sm-8">{{ $resort->contact_number }}{{ $resort->email ? ' · '.$resort->email : '' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Check-in and out</dt>
                    <dd class="col-sm-8">
                        {{ \Illuminate\Support\Carbon::parse($resort->check_in_time)->format('g:i A') }}
                        to {{ \Illuminate\Support\Carbon::parse($resort->check_out_time)->format('g:i A') }}
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Submitted</dt>
                    <dd class="col-sm-8 mb-0">{{ $resort->submitted_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                </dl>
            </section>

            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-2">Location</h2>
                <p class="text-muted-vd small">Pin: {{ $resort->latitude }}, {{ $resort->longitude }}</p>
                <x-location-map :locate="false" :latitude="$resort->latitude" :longitude="$resort->longitude" :label="$resort->name"
                                style="height: 18rem" />
            </section>

            <div class="row g-4">
                <div class="col-md-6">
                    <section class="vd-panel p-4 h-100">
                        <h2 class="h5 mb-3">Amenities</h2>
                        @if ($resort->amenities->isEmpty())
                            <p class="text-muted-vd mb-0">None listed.</p>
                        @else
                            <ul class="vd-amenity-list">
                                @foreach ($resort->amenities as $amenity)
                                    <li><i class="bi bi-{{ $amenity->icon ?? 'check-lg' }}" aria-hidden="true"></i>{{ $amenity->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
                <div class="col-md-6">
                    <section class="vd-panel p-4 h-100">
                        <h2 class="h5 mb-3">Rooms</h2>
                        @if ($resort->rooms->isEmpty())
                            <p class="text-muted-vd mb-0">No room types yet. The listing can still be approved.</p>
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach ($resort->rooms as $room)
                                    <li class="d-flex justify-content-between border-top py-2">
                                        <span>{{ $room->name }} <span class="text-muted-vd small">({{ $room->total_rooms }} units)</span></span>
                                        <span class="text-muted-vd">@peso($room->price_per_night)</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Owner</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Name</dt>
                    <dd class="col-7">{{ $resort->owner?->full_name }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Business</dt>
                    <dd class="col-7">{{ $resort->owner?->ownerProfile?->business_name ?? '—' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Email</dt>
                    <dd class="col-7 text-break"><a href="mailto:{{ $resort->owner?->email }}">{{ $resort->owner?->email }}</a></dd>

                    <dt class="col-5 text-muted-vd fw-normal">Contact</dt>
                    <dd class="col-7">{{ $resort->owner?->phone ?? '—' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Account</dt>
                    <dd class="col-7 mb-0">
                        <x-status-badge :status="$resort->owner->status" />
                        <a class="d-block small mt-1" href="{{ route('admin.users.show', $resort->owner) }}">Open account</a>
                    </dd>
                </dl>
            </section>

            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Before approving</h2>
                <ul class="list-unstyled mb-0 small">
                    @foreach ($checklist as $item)
                        <li class="d-flex gap-2 py-1">
                            <i class="bi bi-{{ $item['passed'] ? 'check-circle-fill' : ($item['required'] ? 'x-octagon-fill' : 'exclamation-triangle-fill') }}
                                      vd-check-icon--{{ $item['passed'] ? 'pass' : ($item['required'] ? 'fail' : 'warn') }} mt-1" aria-hidden="true"></i>
                            <span>
                                {{ $item['label'] }}
                                @unless ($item['passed'])
                                    <span class="d-block text-muted-vd">{{ $item['hint'] }}</span>
                                @endunless
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Decision</h2>

                @if ($resort->status === \App\Enums\ResortStatus::Pending)
                    @if ($blockers !== [])
                        <div class="alert alert-warning" role="status">
                            This listing cannot be approved yet: {{ implode('; ', $blockers) }}.
                        </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2">
                        @if ($blockers !== [])
                            <span class="btn btn-primary disabled" aria-disabled="true"
                                  title="Fix the points above first">Approve and publish</span>
                        @elsecan('approve', $resort)
                            <x-confirm-form id="approve-resort"
                                            :action="route('admin.resort-applications.approve', $resort)"
                                            title="Publish this resort?"
                                            :message="$resort->name.' becomes visible to visitors and its rooms can be booked.'"
                                            trigger="Approve and publish"
                                            confirm="Yes, publish"
                                            icon="check-lg"
                                            variant="primary" />
                        @endif

                        <x-confirm-form id="reject-resort"
                                        :action="route('admin.resort-applications.reject', $resort)"
                                        title="Send this listing back?"
                                        message="The owner sees your notes, fixes the listing, and can send it again."
                                        trigger="Send back"
                                        confirm="Send back to owner"
                                        icon="arrow-counterclockwise"
                                        variant="danger"
                                        trigger-class="btn btn-outline-danger">
                            <div class="mt-3">
                                <label class="form-label" for="review_remarks">What needs fixing</label>
                                <textarea class="form-control @error('review_remarks') is-invalid @enderror"
                                          id="review_remarks" name="review_remarks" rows="3" required
                                          placeholder="For example: the map pin is in the wrong barangay.">{{ old('review_remarks') }}</textarea>
                                @error('review_remarks')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </x-confirm-form>
                    </div>
                @else
                    <p class="mb-3">
                        This listing is <x-status-badge :status="$resort->status" />
                        @if ($resort->reviewed_at) since {{ $resort->reviewed_at->format('M j, Y') }} @endif.
                    </p>
                    @if (filled($resort->review_remarks))
                        <p class="text-muted-vd small">Your notes: {{ $resort->review_remarks }}</p>
                    @endif
                    <a class="btn btn-outline-primary" href="{{ route('admin.resorts.show', $resort) }}">Manage this listing</a>
                @endif
            </section>
        </div>
    </div>
@endsection
