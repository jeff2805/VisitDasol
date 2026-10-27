@extends('layouts.public')

@section('title', 'Destinations')
@section('meta_description', 'Beaches, viewpoints, and other places to visit in Dasol, Pangasinan.')

@section('content')
    <div class="container py-5">
        <x-page-header title="Places to see in Dasol"
                       subtitle="Destinations listed by the Municipal Tourism Office." />

        {{-- The same filter shell as the room listing. Two public listings had
             two different arrangements of the same controls — a bare row here, a
             panel there — which made them read as two different sites. The
             categories stay links rather than a menu, because a category is also
             a page worth sharing. --}}
        <div class="vd-panel p-3 p-lg-4 vd-filterbar mb-4">
            <form method="GET" action="{{ route('destinations.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-sm-8 col-lg-5">
                        <label class="form-label" for="filter-q">Search</label>
                        <input type="search" class="form-control" id="filter-q" name="q"
                               value="{{ $search }}" placeholder="Name or barangay">
                    </div>

                    {{-- The chosen category survives a search, so the two
                         filters combine instead of cancelling each other. --}}
                    @if ($category)
                        <input type="hidden" name="category" value="{{ $category->slug }}">
                    @endif

                    <div class="col-sm-4 col-lg-auto">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                    </div>

                    @if ($search !== '' || $category)
                        <div class="col-sm-4 col-lg-auto">
                            <a class="btn btn-light border w-100" href="{{ route('destinations.index') }}">Clear</a>
                        </div>
                    @endif
                </div>
            </form>

            @if ($categories->isNotEmpty())
                <div class="vd-filterbar__pills">
                    <h2 class="visually-hidden">Categories</h2>

                    <ul class="nav nav-pills gap-1">
                        <li class="nav-item">
                            <a class="nav-link @if (! $category) active @endif"
                               href="{{ route('destinations.index', ['q' => $search ?: null]) }}">All</a>
                        </li>
                        @foreach ($categories as $item)
                            <li class="nav-item">
                                <a class="nav-link @if ($category?->id === $item->id) active @endif"
                                   href="{{ route('destinations.index', ['category' => $item->slug, 'q' => $search ?: null]) }}">
                                    @if ($item->icon)<i class="bi bi-{{ $item->icon }} me-1" aria-hidden="true"></i>@endif
                                    {{ $item->name }}
                                    <span class="badge rounded-pill text-bg-light ms-1">{{ $item->destinations_count }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        @if ($destinations->isEmpty())
            <x-empty-state icon="signpost-2" title="No destinations to show"
                           message="Published destinations appear here. Try clearing the filters.">
                <a class="btn btn-primary" href="{{ route('destinations.index') }}">Clear filters</a>
            </x-empty-state>
        @else
            {{-- Named so the page does not jump from its h1 straight to the
                 h3 on each card. --}}
            <h2 class="visually-hidden">Destinations matching your filters</h2>

            <div class="row g-4">
                @foreach ($destinations as $destination)
                    <div class="col-md-6 col-lg-4">
                        <x-destination-card :destination="$destination" />
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $destinations->links() }}</div>
        @endif
    </div>
@endsection
