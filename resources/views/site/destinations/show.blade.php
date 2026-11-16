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
