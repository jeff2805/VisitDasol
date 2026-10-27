{{--
    "Where, when, how many" — VisitDasol stay search.
    Includes barangay, check-in/check-out dates and times, and guests.
--}}
@props([
    'barangays' => [],
    'action' => null,
    'values' => [],
])

@php
    $tomorrow = now()->addDay();
    $dayAfter = now()->addDays(3);

    $checkIn = $values['check_in'] ?? request('check_in', '');
    $checkOut = $values['check_out'] ?? request('check_out', '');

    $checkInTime = $values['check_in_time'] ?? request('check_in_time', '14:00');
    $checkOutTime = $values['check_out_time'] ?? request('check_out_time', '12:00');
@endphp

<form class="vd-stay-search" method="GET"
      action="{{ $action ?? route('resorts.index') }}">

    {{-- Barangay --}}
    <div class="vd-stay-search__field vd-stay-search__field--where">
        <label class="form-label" for="stay-barangay">Where</label>

        <select class="form-select" id="stay-barangay" name="barangay">
            <option value="">Anywhere in {{ config('visitdasol.municipality') }}</option>

            @foreach ($barangays as $barangay)
                <option value="{{ $barangay }}"
                    @selected(($values['barangay'] ?? request('barangay')) === $barangay)>
                    Brgy. {{ $barangay }}
                </option>
            @endforeach
        </select>
    </div>

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

    {{-- Guest capacity --}}
    <div class="vd-stay-search__field vd-stay-search__field--guests">
        <label class="form-label" for="stay-guests">Guests</label>

        <input
            class="form-control"
            type="number"
            id="stay-guests"
            name="guests"
            min="1"
            max="30"
            inputmode="numeric"
            value="{{ $values['guests'] ?? request('guests', '') }}"
            placeholder="Any"
        >
    </div>

    {{-- Search --}}
    <div class="vd-stay-search__go">
        <button type="submit" class="btn btn-accent btn-lg w-100">
            <i class="bi bi-search me-1" aria-hidden="true"></i>
            Search
        </button>
    </div>
</form>

{{-- The check-out minimum follows the check-in date. That used to be an
     inline <script> here, which the Content Security Policy blocks
     outright — so it never ran for a single real visitor, and a guest
     could ask for a check-out before their arrival. It lives in app.js
     now, hung off the two data attributes above. --}}