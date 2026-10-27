{{-- Destination summary card for listings and the home page. --}}
@props(['destination'])

<article {{ $attributes->class('vd-card h-100') }}>
    <a class="vd-card__media" href="{{ route('destinations.show', $destination) }}" tabindex="-1" aria-hidden="true">
        <img src="{{ $destination->main_image_url }}" alt="" loading="lazy">
        @if ($destination->is_featured)
            <span class="vd-card__flag">Featured</span>
        @endif
    </a>

    <div class="vd-card__body">
        <p class="vd-card__eyebrow">
            @if ($destination->category?->icon)<i class="bi bi-{{ $destination->category->icon }}" aria-hidden="true"></i>@endif
            {{ $destination->category?->name }}
        </p>

        <h3 class="vd-card__title">
            <a href="{{ route('destinations.show', $destination) }}">{{ $destination->name }}</a>
        </h3>

        @if ($destination->barangay)
            <p class="vd-card__meta">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                Brgy. {{ $destination->barangay }}
            </p>
        @endif

        <p class="vd-card__text">{{ Str::limit($destination->description, 120) }}</p>
    </div>

    {{-- The way in, where the resort and room cards also put it. Without a
         footer this card ended at its description, so a row of mixed cards had
         no common baseline and a spot was the only one of the three with no
         visible way in — only the title and the photo were clickable.

         Nothing sits on the left, unlike those two: a spot has no price, and
         the schema requires its coordinates, so "on the map" would be a line
         that is true of every single spot and tells a visitor nothing. --}}
    <div class="vd-card__footer vd-card__footer--end">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('destinations.show', $destination) }}">View details</a>
    </div>
</article>
