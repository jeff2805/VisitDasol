@extends('layouts.owner')

@section('title', 'My resort')

@php($limit = config('visitdasol.uploads.gallery_limits.resort'))

@section('content')
    <x-page-header :title="$resort->name" :subtitle="$resort->full_address">
        <a class="btn btn-primary" href="{{ route('owner.resort.edit') }}">
            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit details
        </a>
        @if ($resort->isPubliclyVisible())
            <x-external-link class="btn btn-outline-primary" :href="route('resorts.show', $resort)">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>View public page
            </x-external-link>
        @endif
    </x-page-header>

    {{-- Status banner --}}
    @switch($resort->status)
        @case(\App\Enums\ResortStatus::Pending)
            <div class="alert alert-warning d-flex gap-2" role="status">
                <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
                <div>
                    <strong class="d-block">Waiting for review</strong>
                    Submitted {{ $resort->submitted_at?->diffForHumans() }}. Guests cannot see or book this listing yet.
                    You can keep editing while you wait.
                </div>
            </div>
            @break

        @case(\App\Enums\ResortStatus::Rejected)
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <strong class="d-block">Not approved</strong>
                    {{ $resort->review_remarks }}
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-light border" href="{{ route('owner.resort.edit') }}">Edit details</a>
                        <x-confirm-form id="resubmit-resort"
                                        :action="route('owner.resort.resubmit')"
                                        title="Send this listing back for review?"
                                        message="The tourism office will look at your updated details."
                                        trigger="Send for review again"
                                        confirm="Yes, send it"
                                        variant="primary"
                                        trigger-class="btn btn-sm btn-primary" />
                    </div>
                </div>
            </div>
            @break

        @case(\App\Enums\ResortStatus::Inactive)
            <div class="alert alert-warning d-flex gap-2" role="status">
                <i class="bi bi-eye-slash mt-1" aria-hidden="true"></i>
                <div>
                    <strong class="d-block">Hidden from the public</strong>
                    The tourism office has taken this listing offline. Contact the office for details.
                </div>
            </div>
            @break

        @default
            <div class="alert alert-success d-flex gap-2" role="status">
                <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
                <div>
                    <strong class="d-block">Live on Visit Dasol</strong>
                    Guests can see this listing. Edits you save appear straight away.
                </div>
            </div>
    @endswitch

    @if (blank($resort->main_image))
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="bi bi-image mt-1" aria-hidden="true"></i>
            <div>
                @if ($resort->status === \App\Enums\ResortStatus::Active)
                    Add a cover photo so guests see your resort at its best.
                @else
                    Add a cover photo. The tourism office asks for one before approving a listing.
                @endif
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <h2 class="h5 mb-0">Listing details</h2>
                    <x-status-badge :status="$resort->status" />
                </div>

                <img class="vd-gallery__main mb-3" src="{{ $resort->main_image_url }}" alt="">

                <p style="white-space: pre-line">{{ $resort->description }}</p>

                <dl class="row mb-0 small border-top pt-3">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Barangay</dt>
                    <dd class="col-sm-8">{{ $resort->barangay }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact</dt>
                    <dd class="col-sm-8">{{ $resort->contact_number }}{{ $resort->email ? ' · '.$resort->email : '' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Check-in and out</dt>
                    <dd class="col-sm-8">
                        {{ \Illuminate\Support\Carbon::parse($resort->check_in_time)->format('g:i A') }}
                        to {{ \Illuminate\Support\Carbon::parse($resort->check_out_time)->format('g:i A') }}
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Map pin</dt>
                    <dd class="col-sm-8 mb-0">{{ $resort->latitude }}, {{ $resort->longitude }}</dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Photos</h2>
                    <span class="text-muted-vd small">{{ $resort->images->count() }} of {{ $limit }}</span>
                </div>

                @error('images')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                @if ($resort->images->isEmpty())
                    <p class="text-muted-vd">No photos yet. Guests are far more likely to book a listing with photos.</p>
                @else
                    <div class="row g-3 mb-3">
                        @foreach ($resort->images as $image)
                            <div class="col-6 col-md-4">
                                <figure class="mb-0">
                                    <img class="w-100 rounded" style="aspect-ratio: 4/3; object-fit: cover" src="{{ $image->url }}" alt="{{ $image->caption }}">
                                    <figcaption class="d-flex gap-1 mt-1">
                                        @if ($resort->main_image === $image->path)
                                            <span class="vd-badge vd-badge--active">Cover</span>
                                        @else
                                            <form method="POST" action="{{ route('owner.resort.images.cover', $image) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border">Use as cover</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('owner.resort.images.destroy', $image) }}"
                                              data-confirm="Remove this photo?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-light border text-danger" aria-label="Remove photo">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </figcaption>
                                </figure>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($resort->images->count() < $limit)
                    <form method="POST" action="{{ route('owner.resort.images.store') }}" enctype="multipart/form-data" data-submit-once>
                        @csrf
                        <label class="form-label" for="field-images">Add photos</label>
                        <div class="input-group has-validation">
                            <input type="file" class="form-control @error('images.0') is-invalid @enderror"
                                   id="field-images" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" required>
                            <button type="submit" class="btn btn-primary">Upload</button>
                            @error('images.0')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-text">JPG, PNG, or WEBP up to 3 MB each. You can choose several at once.</div>
                    </form>
                @endif
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Amenities</h2>
                @if ($resort->amenities->isEmpty())
                    <p class="text-muted-vd mb-0">None chosen yet. <a href="{{ route('owner.resort.edit') }}">Add some</a>.</p>
                @else
                    <ul class="vd-amenity-list">
                        @foreach ($resort->amenities as $amenity)
                            <li><i class="bi bi-{{ $amenity->icon ?? 'check-lg' }}" aria-hidden="true"></i>{{ $amenity->name }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Rooms</h2>
                @if ($resort->rooms->isEmpty())
                    <p class="text-muted-vd">No room types yet. Guests can only book once you add rooms with rates.</p>
                @else
                    <ul class="list-unstyled mb-3">
                        @foreach ($resort->rooms as $room)
                            <li class="d-flex justify-content-between border-top py-2">
                                <span>{{ $room->name }}</span>
                                <span class="text-muted-vd">@peso($room->price_per_night) / night</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a class="btn btn-outline-primary" href="{{ route('owner.rooms.index') }}">Manage rooms</a>
            </section>
        </div>
    </div>
@endsection
