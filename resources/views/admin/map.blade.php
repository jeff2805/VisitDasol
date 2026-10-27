@extends('layouts.admin')

@section('title', 'Map')

@section('content')
    <x-page-header title="Map of every listing"
                   subtitle="Includes listings that visitors cannot see, so you can check where each pin sits." />

    {{-- A barangay with no point is simply absent from the map, which looks
         like nothing being wrong until somebody counts. So it is named. --}}
    @if ($missingBarangays !== [])
        <div class="alert alert-info" role="status">
            <strong class="d-block mb-1">
                {{ count($missingBarangays) }} of 18
                {{ Str::plural('barangay', count($missingBarangays)) }} has no point on the map yet
            </strong>
            {{ implode(', ', $missingBarangays) }}.
            <span class="d-block small mt-2">
                OpenStreetMap has nothing confirmable for these, and a wrong pin on a municipal
                map is worse than no pin. To add one, read the coordinates off the map on any
                destination form and paste them into <code>barangay_points</code> in
                <code>config/visitdasol.php</code>, with <code>'source' =&gt; 'office'</code>.
                Dashed labels on the map are placed from a nearby landmark and are not confirmed either.
            </span>
        </div>
    @endif

    <p class="vd-map-legend mb-3">
        <span class="d-inline-flex align-items-center gap-2">
            <span class="vd-pin vd-pin--resort"><i class="bi bi-house-heart-fill" aria-hidden="true"></i></span>
            Resorts
        </span>
        <span class="d-inline-flex align-items-center gap-2">
            <span class="vd-pin vd-pin--destination"><i class="bi bi-signpost-2-fill" aria-hidden="true"></i></span>
            Destinations
        </span>
        <span class="d-inline-flex align-items-center gap-2">
            <span class="vd-pin vd-pin--muted"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i></span>
            Not published
        </span>
    </p>

    @if ($points->isEmpty())
        <x-empty-state icon="map" title="Nothing to show yet"
                       message="Resorts and destinations appear here as soon as they have coordinates." />
    @else
        <div class="row g-4">
            <div class="col-xl-8">
                <x-visit-map :points="$points" :barangays="$barangays" class="vd-map--tall" />
            </div>

            <div class="col-xl-4">
                <div class="vd-panel">
                    <h2 class="h6 px-3 pt-3">Resorts ({{ $resorts->count() }})</h2>
                    <div class="vd-map-list">
                        @foreach ($resorts as $resort)
                            <button type="button" class="vd-map-list__item" data-map-focus="{{ $resort['id'] }}">
                                <span>
                                    <span class="d-block fw-semibold">{{ $resort['name'] }}</span>
                                    <span class="small text-muted-vd">{{ $resort['subtitle'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>

                    <h2 class="h6 px-3 pt-3">Destinations ({{ $destinations->count() }})</h2>
                    <div class="vd-map-list">
                        @foreach ($destinations as $destination)
                            <button type="button" class="vd-map-list__item" data-map-focus="{{ $destination['id'] }}">
                                <span>
                                    <span class="d-block fw-semibold">{{ $destination['name'] }}</span>
                                    <span class="small text-muted-vd">{{ $destination['subtitle'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
