{{--
    Resort summary card for listings and the home page.
    Expects a publicly visible resort; add withMin/withMax aggregates for
    "from" price and capacity when you have them.
--}}
@props(['resort'])

<article {{ $attributes->class('vd-card h-100') }}>
    <a class="vd-card__media" href="{{ route('resorts.show', $resort) }}" tabindex="-1" aria-hidden="true">
        <img src="{{ $resort->main_image_url }}" alt="" loading="lazy">
        @if ($resort->is_featured)
            <span class="vd-card__flag">Featured</span>
        @endif
    </a>

    <div class="vd-card__body">
        <h3 class="vd-card__title">
            <a href="{{ route('resorts.show', $resort) }}">{{ $resort->name }}</a>
        </h3>

        <p class="vd-card__meta">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            Brgy. {{ $resort->barangay }}, {{ $resort->municipality }}
        </p>

        {{-- Only where the listing asked for it with withRating(). --}}
        @isset($resort->rating_count)
            @if ($resort->rating_count > 0)
                <p class="vd-card__meta">
                    <x-stars :rating="$resort->rating_average" :count="$resort->rating_count" />
                </p>
            @endif
        @endisset

        <p class="vd-card__text">{{ Str::limit($resort->description, 110) }}</p>

        @if ($resort->amenities->isNotEmpty())
            <ul class="vd-card__tags">
                @foreach ($resort->amenities->take(3) as $amenity)
                    <li>@if ($amenity->icon)<i class="bi bi-{{ $amenity->icon }}" aria-hidden="true"></i>@endif {{ $amenity->name }}</li>
                @endforeach
                @if ($resort->amenities->count() > 3)
                    <li>+{{ $resort->amenities->count() - 3 }} more</li>
                @endif
            </ul>
        @endif
    </div>

    <div class="vd-card__footer">
        <span>
            @if (filled($resort->starting_price ?? null))
                <span class="text-muted-vd small d-block">From</span>
                <strong class="vd-card__price">@peso($resort->starting_price)</strong>
                <span class="text-muted-vd small">/ night</span>
            @else
                <span class="text-muted-vd small">Rates not listed yet</span>
            @endif
        </span>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('resorts.show', $resort) }}">View details</a>
    </div>
</article>
