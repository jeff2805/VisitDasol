{{--
    What a reservation costs, line by line, as the guest, the resort and
    the tourism office all need to read it.

    The figures are the ones snapshotted on the booking when it was made,
    never recalculated here: a resort that changes its rates next month
    must not change what a guest was quoted in writing last month.
--}}
@props(['booking'])

@php
    $hasDiscount = (float) $booking->discount_total > 0;
@endphp

<dl {{ $attributes->merge(['class' => 'row mb-0']) }}>
    {{-- A tour is priced for the booking, not by the night, and has none
         to multiply by. Showing "x 0 nights" against a figure that is
         plainly not zero is how a correct bill comes to look wrong. --}}
    <dt class="col-8 text-muted-vd fw-normal">
        @peso($booking->unit_price)
        @if ($booking->booking_type->isOvernight())
            x {{ $booking->nights }} {{ Str::plural('night', $booking->nights) }}
        @endif
        x {{ $booking->rooms_booked }} {{ Str::plural('room', $booking->rooms_booked) }}
    </dt>
    <dd class="col-4 text-end mb-0">@peso($booking->subtotal_amount, true)</dd>

    @if ((float) $booking->child_discount_amount > 0)
        <dt class="col-8 text-muted-vd fw-normal">
            Child discount
            @if ($booking->child_discount_percent > 0)
                <span class="small">({{ $booking->child_discount_percent }}% off their share)</span>
            @endif
        </dt>
        <dd class="col-4 text-end mb-0 text-success">
            &minus;@peso($booking->child_discount_amount, true)
        </dd>
    @endif

    @if ((float) $booking->pwd_discount_amount > 0)
        <dt class="col-8 text-muted-vd fw-normal">
            PWD discount
            @if ($booking->pwd_discount_percent > 0)
                <span class="small">({{ $booking->pwd_discount_percent }}% off their share)</span>
            @endif
        </dt>
        <dd class="col-4 text-end mb-0 text-success">
            &minus;@peso($booking->pwd_discount_amount, true)
        </dd>
    @endif

    <dt class="col-8 fw-semibold border-top pt-2">Total</dt>
    <dd class="col-4 text-end fw-semibold border-top pt-2 mb-0">
        @peso($booking->total_amount, true)
    </dd>
</dl>

@if ($hasDiscount)
    {{-- The discount rests on documents the resort has not seen yet, so
         saying so here is fairer than letting a guest arrive expecting a
         figure the gate may not honour. --}}
    <p class="form-text mb-0 mt-2">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Discounts are applied to the share of the bill belonging to the guests who
        qualify. The resort checks IDs and ages on arrival.
    </p>
@endif
