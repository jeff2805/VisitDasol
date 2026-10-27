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
        <x-stay-search class="mb-4"
                       :barangays="$barangays"
                       :values="[
                           'barangay' => $filters['barangay'],
                           'check_in' => $filters['check_in']?->toDateString(),
                           'check_out' => $filters['check_out']?->toDateString(),
                           'guests' => $filters['guests'],
                       ]" />

        @if ($filters['error'])
            <div class="alert alert-warning" role="status">{{ $filters['error'] }}</div>
        @elseif ($filters['nights'] > 0)
            <p class="text-muted-vd">
                Showing what is free for
                {{ $filters['check_in']->format('M j') }} to {{ $filters['check_out']->format('M j, Y') }}
                ({{ $filters['nights'] }} {{ Str::plural('night', $filters['nights']) }}).
            </p>
        @endif

        {{-- The filters run across the top rather than down one side.

             A narrow column squeezed every control to about a third of the
             page width and pushed the rooms — the thing people came for —
             into the remaining two thirds. Across the top, each field gets
             room to be read and the rooms below get the full width, which is
             one more card per row on a wide screen. --}}
        <form method="GET" action="{{ route('resorts.index') }}" class="vd-panel p-3 p-lg-4 vd-filterbar mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-4 col-xl-3">
                    <label class="form-label" for="filter-q">Search</label>
                    <input type="search" class="form-control" id="filter-q" name="q"
                           value="{{ $filters['q'] }}" placeholder="Room, resort, or barangay">
                </div>

                <div class="col-sm-6 col-lg-4 col-xl-2">
                    <label class="form-label" for="filter-barangay">Barangay</label>
                    <select class="form-select" id="filter-barangay" name="barangay">
                        <option value="">Anywhere in Dasol</option>
                        @foreach ($barangays as $barangay)
                            <option value="{{ $barangay }}" @selected($filters['barangay'] === $barangay)>{{ $barangay }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-sm-6 col-lg-4 col-xl-2">
                    <label class="form-label" for="filter-type">Room category</label>
                    <select class="form-select" id="filter-type" name="type">
                        <option value="">Any kind</option>
                        @foreach ($roomTypes as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-lg-3 col-xl-1">
                    <label class="form-label" for="filter-guests">Guests</label>
                    <input type="number" class="form-control" id="filter-guests" name="guests" min="1" max="30"
                           value="{{ $filters['guests'] }}" placeholder="Any">
                </div>

                <div class="col-6 col-lg-3 col-xl-2">
                    <label class="form-label" for="filter-price">Max ₱/night</label>
                    <input type="number" class="form-control" id="filter-price" name="max_price" min="0" step="100"
                           value="{{ $filters['max_price'] }}" placeholder="Any">
                </div>

                <div class="col-sm-6 col-lg-3 col-xl-2">
                    <label class="form-label" for="filter-sort">Sort by</label>
                    <select class="form-select" id="filter-sort" name="sort">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($amenities->isNotEmpty())
                @php($chosen = count($filters['amenities']))

                {{-- Folded away, because a dozen checkboxes across the top
                     would be taller than the filters they belong to. Open
                     whenever any are in use: a filter nobody can see is a
                     filter nobody can undo. --}}
                <details class="vd-filterbar__more"{{ $chosen ? ' open' : '' }}>
                    <summary>
                        Amenities
                        @if ($chosen)
                            <span class="vd-filterbar__count">{{ $chosen }} chosen</span>
                        @endif
                    </summary>

                    <fieldset class="vd-filterbar__amenities">
                        <legend class="visually-hidden">Amenities</legend>

                        @foreach ($amenities as $amenity)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="amenities[]" value="{{ $amenity->id }}"
                                       id="amenity-{{ $amenity->id }}" @checked(in_array($amenity->id, $filters['amenities'], true))>
                                <label class="form-check-label" for="amenity-{{ $amenity->id }}">{{ $amenity->name }}</label>
                            </div>
                        @endforeach
                    </fieldset>
                </details>
            @endif

            <div class="vd-filterbar__actions">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a class="btn btn-light border" href="{{ route('resorts.index') }}">Clear</a>
            </div>
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
