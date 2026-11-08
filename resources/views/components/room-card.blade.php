{{--
    One bookable room type, as a visitor browsing sees it.

    The room is the subject and the resort is the address: a guest is choosing
    a bed at a price, and needs to know where it is. Expects the room's resort
    and images to be loaded already.
--}}
{{-- free: units still open for the dates the visitor searched, or null when
     they named none. Nothing is shown at all in that case, because "we did
     not ask" and "none left" must never look the same. --}}
{{-- stay: the dates and party the visitor searched with, so "Book now"
     can carry them straight into the booking form. An empty array is
     fine — see the fallback below. --}}
@props(['room', 'free' => null, 'stay' => []])

@php
    $resort = $room->resort;
    // The room's own photo first. Falling back to the resort's cover is
    // better than a grey box, but a room photographed in its own right is
    // what a guest is actually trying to look at.
    $photo = $room->images->first()?->url ?? $resort?->main_image_url;
    $link = $resort ? route('resorts.show', $resort) : null;

    /*
     * STRAIGHT TO THE BOOKING FORM FOR THIS ROOM.
     *
     * ONLY THE ROOM ID TRAVELS, and that is the point. The form loads
     * the room from the database and takes its resort, name, price and
     * capacity from there, so none of them can be altered on the way —
     * a price in a query string is a price a visitor can edit. The
     * dates and party size are the visitor's own answers and are
     * re-checked against the room when the request is saved.
     *
     * The same five parameters the resort page's "Request this room"
     * already uses, so both doors into the form open the same way.
     *
     * Dates are defaulted when the visitor has not searched with any.
     * The form refuses to draw without them — it says to choose dates
     * on the resort page first — which would make this button a dead
     * end on the listing's default view. Tomorrow to three days later
     * is what the resort page's own "Check availability" link assumes,
     * and both date fields are editable on the form that opens.
     */
    $bookNow = route('tourist.bookings.create', [
        'room' => $room->id,
        'check_in' => $stay['check_in'] ?? now()->addDay()->toDateString(),
        'check_in_time' => $stay['check_in_time'] ?? '14:00',
        'check_out' => $stay['check_out'] ?? now()->addDays(3)->toDateString(),
        'check_out_time' => $stay['check_out_time'] ?? '12:00',
        'guests' => $stay['guests'] ?? 1,
    ]);
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

        {{-- Two ways on, with the booking one leading. "Book now" is the
             gold accent the resort page already uses for "Request this
             room"; "View details" keeps the outline it has always had.

             A fully booked room still offers both: the form re-checks
             availability against the dates the guest finally picks, and
             those need not be the ones searched with here. --}}
        <span class="vd-card__actions">
            <a class="btn btn-sm btn-accent" href="{{ $bookNow }}">
                <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>
                Book now
            </a>

            <a class="btn btn-sm btn-outline-primary" href="{{ $link }}">View details</a>
        </span>
    </div>
</article>
