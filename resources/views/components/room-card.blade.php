{{--
    One bookable room type, as a visitor browsing sees it.

    The room is the subject and the resort is the address: a guest is choosing
    a bed at a price, and needs to know where it is. Expects the room's resort
    and images to be loaded already.
--}}
{{-- free: units still open for the dates the visitor searched, or null when
     they named none. Nothing is shown at all in that case, because "we did
     not ask" and "none left" must never look the same. --}}
@props(['room', 'free' => null])

@php
    $resort = $room->resort;
    // The room's own photo first. Falling back to the resort's cover is
    // better than a grey box, but a room photographed in its own right is
    // what a guest is actually trying to look at.
    $photo = $room->images->first()?->url ?? $resort?->main_image_url;
    $link = $resort ? route('resorts.show', $resort) : null;
@endphp

<article {{ $attributes->class('vd-card h-100') }}>
    @if ($link)
        <a class="vd-card__media" href="{{ $link }}" tabindex="-1" aria-hidden="true">
            <img src="{{ $photo }}" alt="" loading="lazy">
            @if ($resort?->is_featured)
                <span class="vd-card__flag">Featured</span>
            @endif
        </a>
    @endif

    <div class="vd-card__body">
        <h3 class="vd-card__title">
            <a href="{{ $link }}">{{ $room->name }}</a>
        </h3>

        {{-- Which resort, and where. Without this a room name on its own
             ("Deluxe Room") says nothing about where a guest would be sleeping. --}}
        <p class="vd-card__meta">
            <i class="bi bi-house-heart" aria-hidden="true"></i>
            {{ $resort?->name }}
        </p>
        <p class="vd-card__meta">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            Brgy. {{ $resort?->barangay }}, {{ $resort?->municipality }}
        </p>
        {{-- Resort rating and review count --}}
@if ($resort)
    <div class="mt-2 mb-2">
        <x-stars
            :rating="$resort->rating_average"
            :count="$resort->rating_count"
        />
    </div>
@endif

        <ul class="vd-card__tags">
            <li><i class="bi bi-door-open" aria-hidden="true"></i> {{ $room->room_type->label() }}</li>
            <li>
                <i class="bi bi-people" aria-hidden="true"></i>
                Up to {{ $room->capacity }} {{ Str::plural('guest', $room->capacity) }}
            </li>

            {{-- Day and night tours, when the resort runs them. A visitor
                 who only wants the beach for an afternoon has no reason
                 to open a card that reads like a hotel room. --}}
            @foreach ($room->bookingTypes() as $case)
                @continue ($case->isOvernight())
                <li>
                    <i class="bi bi-{{ $case->icon() }}" aria-hidden="true"></i>
                    {{ $case->label() }} @peso($room->priceFor($case))
                </li>
            @endforeach
        </ul>

        @if (! is_null($free))
            {{-- An icon as well as a colour: status must never rest on colour
                 alone, and this is read at a glance on a small screen. --}}
            <p class="mb-0 mt-2">
                @if ($free > 0)
                    <span class="vd-card__free vd-card__free--yes">
                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        {{ $free }} {{ Str::plural('room', $free) }} free for your dates
                    </span>
                @else
                    <span class="vd-card__free vd-card__free--no">
                        <i class="bi bi-slash-circle" aria-hidden="true"></i>
                        Fully booked for your dates
                    </span>
                @endif
            </p>
        @endif

        @if (filled($room->description))
            <p class="vd-card__text">{{ Str::limit($room->description, 100) }}</p>
        @endif
    </div>

    <div class="vd-card__footer">
        <span>
            <strong class="vd-card__price">@peso($room->price_per_night)</strong>
            <span class="text-muted-vd small">/ night</span>
        </span>

        <a class="btn btn-sm btn-outline-primary" href="{{ $link }}">View details</a>
    </div>
</article>
