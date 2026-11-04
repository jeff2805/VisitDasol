
{{--
    VisitDasol Stay Search

    The Rooms page wraps this in its own GET form, so that the orange Search
    button and the filter bar below it submit as one request; it passes
    :standalone="false" and this renders a plain <div>, never a nested <form>.

    Everywhere else (the home hero, the tourist dashboard) there is no parent
    form, so the component supplies its own. Without it the Search button is a
    submit button with nothing to submit and the hero search does nothing.
--}}
@props([
    'barangays' => [],
    'action' => null,
    'values' => [],
    'standalone' => true,
])

@php
    $tomorrow = now()->addDay();

    // Same element and class either way, so the layout is identical whether
    // this page owns the form or the Rooms page does.
    $tag = $standalone ? 'form' : 'div';

    $checkIn = $values['check_in'] ?? request('check_in', '');
    $checkOut = $values['check_out'] ?? request('check_out', '');

    $checkInTime = $values['check_in_time'] ?? request('check_in_time', '14:00');
    $checkOutTime = $values['check_out_time'] ?? request('check_out_time', '12:00');
@endphp

<{{ $tag }} class="vd-stay-search"
    @if ($standalone) method="GET" action="{{ $action ?? route('resorts.index') }}" @endif>

    {{-- Check-in date --}}
    <div class="vd-stay-search__field">
        <label class="form-label" for="stay-check-in">Check-in Date</label>
        <input
            class="form-control"
            type="date"
            id="stay-check-in"
            name="check_in"
            min="{{ now()->toDateString() }}"
            value="{{ $checkIn }}"
            data-stay-check-in
        >
    </div>

    {{-- Check-in time --}}
    <div class="vd-stay-search__field">
        <label class="form-label" for="stay-check-in-time">Check-in Time</label>
        <input
            class="form-control"
            type="time"
            id="stay-check-in-time"
            name="check_in_time"
            value="{{ $checkInTime }}"
            required
        >
    </div>

    {{-- Check-out date --}}
    <div class="vd-stay-search__field">
        <label class="form-label" for="stay-check-out">Check-out Date</label>
        <input
            class="form-control"
            type="date"
            id="stay-check-out"
            name="check_out"
            min="{{ $checkIn ?: $tomorrow->toDateString() }}"
            value="{{ $checkOut }}"
            data-stay-check-out
        >
    </div>

    {{-- Check-out time --}}
    <div class="vd-stay-search__field">
        <label class="form-label" for="stay-check-out-time">Check-out Time</label>
        <input
            class="form-control"
            type="time"
            id="stay-check-out-time"
            name="check_out_time"
            value="{{ $checkOutTime }}"
            required
        >
    </div>

    {{-- Search --}}
    <div class="vd-stay-search__go">
        <button type="submit" class="btn btn-accent btn-lg w-100">
            <i class="bi bi-search me-1" aria-hidden="true"></i>
            Search
        </button>
    </div>

</{{ $tag }}>
