{{--
    Read-only map with one pin.

    <x-location-map :latitude="$resort->latitude" :longitude="$resort->longitude"
                    :label="$resort->name" style="height: 16rem" />

    locate  adds a "Where am I?" button that puts the visitor on the map and
            says how far the pin is. On by default on the public pages,
            because "how far is this from me" is the question a guest reading
            one resort page has. Pass :locate="false" for the office screens,
            which are for checking where a pin sits, not for travelling to it.
--}}
@props(['latitude', 'longitude', 'label' => null, 'zoom' => 15, 'locate' => true])

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        {{-- Shared geometry and formatting. Must come before location-map.js. --}}
        <script src="@vdasset('js/geo.js')"></script>
        <script src="@vdasset('js/location-map.js')"></script>
    @endpush
@endonce

@php($map = config('visitdasol.map'))

{{-- The button and readout are siblings of the map element, which is where
     location-map.js looks for them. --}}
<div class="vd-locate-wrap">
    <div {{ $attributes->class('vd-map') }}
         data-location-map
         @if ($locate) data-locate @endif
         data-points='@json([['lat' => (float) $latitude, 'lng' => (float) $longitude, 'label' => $label]])'
         data-zoom="{{ $zoom }}"
         data-min-zoom="{{ $map['min_zoom'] }}"
         data-max-zoom="{{ $map['max_zoom'] }}"
         data-tile-url="{{ $map['tile_url'] }}"
         data-attribution="{{ $map['attribution'] }}"></div>

    @if ($locate)
        <div class="vd-map-tools mt-2">
            {{-- Hidden until the script is running, so it is never a button
                 that does nothing. --}}
            <button type="button" class="btn btn-sm btn-outline-primary" data-locate-button hidden>
                <i class="bi bi-crosshair me-1" aria-hidden="true"></i>Where am I?
            </button>
            <p class="vd-map-readout mb-0" data-locate-readout role="status" hidden></p>
        </div>
    @endif
</div>
