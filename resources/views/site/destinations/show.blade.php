@extends('layouts.public')

@section('title', $destination->name)
@section('meta_description', Str::limit($destination->description, 150))

@section('content')
    <div class="container py-5">
        <nav aria-label="Breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('destinations.index') }}">Destinations</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $destination->name }}</li>
            </ol>
        </nav>

        <div class="row g-4">
            <div class="col-lg-8">
                <img class="vd-gallery__main" src="{{ $destination->main_image_url }}" alt="{{ $destination->name }}">

                @if ($destination->images->isNotEmpty())
                    <ul class="vd-gallery__strip">
                        @foreach ($destination->images as $image)
                            <li><img src="{{ $image->url }}" alt="{{ $image->caption ?? $destination->name }}" loading="lazy"></li>
                        @endforeach
                    </ul>
                @endif

                <p class="vd-card__eyebrow mt-4 mb-1">
                    @if ($destination->category?->icon)<i class="bi bi-{{ $destination->category->icon }}" aria-hidden="true"></i>@endif
                    {{ $destination->category?->name }}
                </p>
                <h1 class="mb-1">{{ $destination->name }}</h1>
                @if ($destination->barangay)
                    <p class="text-muted-vd"><i class="bi bi-geo-alt" aria-hidden="true"></i> Brgy. {{ $destination->barangay }}, {{ config('visitdasol.municipality') }}</p>
                @endif

                <p class="vd-detail__lead" style="white-space: pre-line">{{ $destination->description }}</p>
            </div>

            <div class="col-lg-4">
                <aside class="vd-panel p-4">
                    <h2 class="h5 mb-2">Getting there</h2>
                    @if (filled($destination->address))
                        <p class="text-muted-vd small">{{ $destination->address }}</p>
                    @endif

                    <x-location-map :latitude="$destination->latitude" :longitude="$destination->longitude"
                                    :label="$destination->name" style="height: 15rem" class="mb-2" />

                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <x-external-link class="btn btn-outline-primary btn-sm"
                           href="https://www.openstreetmap.org/?mlat={{ $destination->latitude }}&mlon={{ $destination->longitude }}#map=16/{{ $destination->latitude }}/{{ $destination->longitude }}">
                            <i class="bi bi-compass me-1" aria-hidden="true"></i>Get directions
                        </x-external-link>
                        <a class="btn btn-light border btn-sm" href="{{ route('map') }}">See all on the map</a>
                    </div>

                    <p class="form-text mb-0">Always check local conditions and advisories before travelling.</p>
                </aside>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-5" aria-labelledby="related-title">
                <h2 id="related-title" class="h4 mb-3">More {{ Str::lower($destination->category?->name) }} spots</h2>
                <div class="row g-4">
                    @foreach ($related as $other)
                        <div class="col-md-6 col-lg-4">
                            <x-destination-card :destination="$other" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
