@extends('layouts.public')

@section('title', 'You already have a booking')

@section('content')
    <div class="container py-5">
        <nav aria-label="Breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="{{ route('resorts.index') }}">Places to stay</a></li>
                <li class="breadcrumb-item">
                    <a href="{{ route('resorts.show', $resort) }}">{{ $resort->name }}</a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">Request a room</li>
            </ol>
        </nav>

        <div class="row g-4">
            <div class="col-lg-8">
                <x-active-booking-notice :booking="$activeBooking" />
            </div>

            {{-- What they were trying to book, so the thread of what they
                 were doing is not lost. They can come back to it the
                 moment the other reservation is finished. --}}
            <div class="col-lg-4">
                <aside class="vd-panel p-4">
                    <p class="vd-card__eyebrow mb-1">You were looking at</p>
                    <h2 class="h5 mb-1">{{ $room->name }}</h2>
                    <p class="text-muted-vd small mb-3">
                        {{ $resort->name }} · Brgy. {{ $resort->barangay }}
                    </p>

                    <img class="vd-booking__room-photo mb-3"
                         src="{{ $room->images->first()?->url ?? $resort->main_image_url }}" alt="">

                    <p class="mb-3">
                        <strong class="vd-card__price">@peso($room->price_per_night)</strong>
                        <span class="text-muted-vd small">per night</span>
                    </p>

                    <a class="btn btn-outline-primary w-100"
                       href="{{ route('resorts.show', $resort) }}">Back to {{ $resort->name }}</a>
                </aside>
            </div>
        </div>
    </div>
@endsection
