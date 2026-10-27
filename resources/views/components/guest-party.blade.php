{{--
    Who is in the party, in one line: "4 guests (3 adults, 1 child) · 1 PWD".

    PWD guests are a subset of the adults and children, never extra
    people, so they are set off after a separator rather than listed
    alongside — a reader adding the figures up must not reach a number
    larger than the party.
--}}
@props(['booking'])

@php
    $parts = [];

    if ($booking->adults > 0) {
        $parts[] = $booking->adults . ' ' . Str::plural('adult', $booking->adults);
    }

    if ($booking->children > 0) {
        $parts[] = $booking->children . ' ' . Str::plural('child', $booking->children);
    }
@endphp

<span {{ $attributes }}>
    {{ $booking->guests }} {{ Str::plural('guest', $booking->guests) }}

    @if (count($parts) > 1)
        <span class="text-muted-vd">({{ implode(', ', $parts) }})</span>
    @endif

    @if ($booking->pwd_guests > 0)
        <span class="text-muted-vd">
            &middot; {{ $booking->pwd_guests }} with a PWD ID
        </span>
    @endif

    @if ($booking->beds > 0)
        <span class="text-muted-vd">
            &middot; {{ $booking->beds }} {{ Str::plural('bed', $booking->beds) }}
        </span>
    @endif
</span>
