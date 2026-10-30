@extends('layouts.public')

@section('title', 'Rooms')
@section('meta_description', 'Rooms at resorts in Dasol, Pangasinan, approved by the Municipal Tourism Office.')

@section('content')
    <div class="container py-5">
        @php($total = $rooms->total())

        <x-page-header title="Rooms in Dasol"
                       :subtitle="$total.' '.Str::plural('room', $total).' at '.$resortCount.' '.Str::plural('resort', $resortCount).' approved by the Municipal Tourism Office.'" />

        {{-- The same search as the home page, carrying what was asked there,
             so dates chosen on the way in can be changed without going back. --}}
       {{-- Combined Stay Search and Room Filters: One Visual Container --}}
        {{-- Stay Search ONLY --}}
<div class="vd-rooms-search-panel mb-4">

    <x-stay-search
        :barangays="$barangays"
        :values="[
            'barangay' => $filters['barangay'],
            'check_in' => $filters['check_in']?->toDateString(),
            'check_out' => $filters['check_out']?->toDateString(),
            'guests' => $filters['guests'],
        ]"
    />
    
{{-- Room Filters --}}
<form method="GET"
      action="{{ route('resorts.index') }}"
      class="vd-filterbar p-3 p-lg-4 mb-0">

    @if(request('category'))
        <input type="hidden" name="category" value="{{ request('category') }}">
    @endif

    <div class="row g-3 align-items-end">

        {{-- Search --}}
        <div class="col-12 col-lg-4 col-xl-3">
            <label class="form-label" for="filter-q">Search</label>
            <input type="search"
                   class="form-control"
                   id="filter-q"
                   name="q"
                   value="{{ $filters['q'] }}"
                   placeholder="Room, resort, or barangay">
        </div>

        {{-- Room Category --}}
        <div class="col-sm-6 col-lg-4 col-xl-2">
            <label class="form-label" for="filter-type">Room category</label>
            <select class="form-select" id="filter-type" name="type">
                <option value="">Any kind</option>
                @foreach ($roomTypes as $value => $label)
                    <option value="{{ $value }}" @selected($filters['type'] === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Guests --}}
        <div class="col-6 col-lg-3 col-xl-1">
            <label class="form-label" for="filter-guests">Guests</label>
            <input type="number"
                   class="form-control"
                   id="filter-guests"
                   name="guests"
                   min="1"
                   max="30"
                   value="{{ $filters['guests'] }}"
                   placeholder="Any">
        </div>

        {{-- Maximum Price --}}
        <div class="col-6 col-lg-3 col-xl-2">
            <label class="form-label" for="filter-price">Max ₱/night</label>
            <input type="number"
                   class="form-control"
                   id="filter-price"
                   name="max_price"
                   min="0"
                   step="100"
                   value="{{ $filters['max_price'] }}"
                   placeholder="Any">
        </div>

        {{-- Sorting --}}
        <div class="col-sm-6 col-lg-3 col-xl-2">
            <label class="form-label" for="filter-sort">Sort by</label>
            <select class="form-select" id="filter-sort" name="sort">
                @foreach ($sorts as $value => $label)
                    <option value="{{ $value }}" @selected($filters['sort'] === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

    </div>

    {{-- Actions --}}
    <div class="vd-filterbar__actions">
        <button type="submit" class="btn btn-primary">
            Apply filters
        </button>

        <a class="btn btn-light border"
           href="{{ route('resorts.index') }}">
            Clear
        </a>
    </div>

</form>

</div>

{{-- Accommodation Category: COMPLETELY SEPARATE --}}
<div class="vd-accommodation-category mb-4">

    <h3>Accommodation Category</h3>

    <div class="vd-accommodation-category__buttons">
        @foreach (['All', 'Budget', 'Premium', 'Cottage'] as $category)
            <a href="{{ route('resorts.index', array_merge(
                request()->except('page', 'category'),
                ['category' => $category === 'All' ? null : $category]
            )) }}"
               class="vd-category-btn {{ request('category', 'All') === $category ? 'active' : '' }}">
                {{ $category }}
            </a>
        @endforeach
    </div>

</div>

{{-- Room Filters: Separate --}}
<form method="GET"
      action="{{ route('resorts.index') }}"
      class="vd-filterbar p-3 p-lg-4 mb-4">

    {{-- KEEP ALL YOUR EXISTING ROOM FILTER CONTENTS HERE --}}

</form>

        @if ($rooms->isEmpty())
            <x-empty-state icon="search" title="No rooms match your filters"
                           message="Try a wider price range, fewer guests, fewer amenities, or another barangay.">
                <a class="btn btn-primary" href="{{ route('resorts.index') }}">Clear filters</a>
            </x-empty-state>
        @else
            {{-- Named so the page does not jump from its h1 straight to the
                 h3 on each card. Somebody moving through this page by heading
                 would otherwise find a list of room names with nothing saying
                 what the list is. --}}
            <h2 class="visually-hidden">Rooms matching your filters</h2>

            {{-- The full width is the point of moving the filters: four cards
                 across on a wide screen instead of three. --}}
            <div class="row g-4">
                @foreach ($rooms as $room)
                    <div class="col-sm-6 col-lg-4 col-xxl-3">
                        <x-room-card :room="$room" :free="$availability[$room->id] ?? null" />
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $rooms->links() }}</div>
        @endif
    </div>
@endsection