{{--
    When the guest has the room, written the way the booking was made.

    Emits <dt>/<dd> pairs, so it goes inside a <dl class="row"> on the
    tourist, owner and office screens alike — all three must read the
    same dates back, and a tour must not read like a short stay.

    A TOUR'S CHECK-OUT DATE IS NOT SHOWN, DELIBERATELY. The engine stores
    a tour as [D, D+1) so the sweep in AvailabilityService holds the room
    for the date; nobody is there on D+1, and printing it would tell a
    day group to come back tomorrow.
--}}
@props(['booking'])

@php
    $type = $booking->booking_type;

    // The booking's own hours, which a tour sets for itself. The resort's
    // standard times are the fallback for rows made before bookings
    // carried their own.
    $from = \Illuminate\Support\Carbon::parse(
        $booking->check_in_time ?? $booking->resort?->check_in_time ?? '14:00'
    );

    $until = \Illuminate\Support\Carbon::parse(
        $booking->check_out_time ?? $booking->resort?->check_out_time ?? '12:00'
    );
@endphp

<dt class="col-sm-4 text-muted-vd fw-normal">Booking</dt>
<dd class="col-sm-8">
    <i class="bi bi-{{ $type->icon() }} me-1" aria-hidden="true"></i>{{ $type->label() }}
</dd>

@if ($type->isOvernight())
    <dt class="col-sm-4 text-muted-vd fw-normal">Check-in</dt>
    <dd class="col-sm-8">
        {{ $booking->check_in_date->format('l, M j, Y') }}
        <span class="text-muted-vd">from {{ $from->format('g:i A') }}</span>
    </dd>

    <dt class="col-sm-4 text-muted-vd fw-normal">Check-out</dt>
    <dd class="col-sm-8">
        {{ $booking->check_out_date->format('l, M j, Y') }}
        <span class="text-muted-vd">by {{ $until->format('g:i A') }}</span>
    </dd>

    <dt class="col-sm-4 text-muted-vd fw-normal">Nights</dt>
    <dd class="col-sm-8">{{ $booking->nights }}</dd>
@else
    <dt class="col-sm-4 text-muted-vd fw-normal">Date</dt>
    <dd class="col-sm-8">{{ $booking->check_in_date->format('l, M j, Y') }}</dd>

    <dt class="col-sm-4 text-muted-vd fw-normal">Hours</dt>
    <dd class="col-sm-8">
        {{ $from->format('g:i A') }} to {{ $until->format('g:i A') }}
        {{-- A night tour leaves after midnight, so the hour alone is
             ambiguous and the day has to be said. --}}
        @if ($type === \App\Enums\BookingType::NightTour)
            <span class="text-muted-vd">the next morning</span>
        @endif
    </dd>

    <dt class="col-sm-4 text-muted-vd fw-normal">Overnight</dt>
    <dd class="col-sm-8">No. The room is held for this date only.</dd>
@endif
