{{--
    Map pin picker for resort and destination forms.

    <x-coordinate-picker :latitude="old('latitude', $resort->latitude)"
                         :longitude="old('longitude', $resort->longitude)" />

    Renders the map plus the two number fields it fills in. The server
    validates the values again, so a hand-typed pin outside Dasol is refused.
--}}
@props(['latitude' => null, 'longitude' => null])

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        <script src="@vdasset('js/coordinate-picker.js')"></script>
    @endpush
@endonce

@php($map = config('visitdasol.map'))

<div {{ $attributes->class('mb-3') }}>
    <p class="form-label mb-1">Location on the map</p>
    <p class="form-text mt-0 mb-2">
        Click the map to drop the pin on your resort, or drag it. The pin must be inside the dashed area,
        which covers {{ config('visitdasol.municipality') }}.
    </p>

    <div class="vd-map mb-2"
         data-coordinate-picker
         data-center='@json($map['center'])'
         data-bounds='@json($map['bounds'])'
         data-zoom="{{ $map['zoom'] }}"
         data-min-zoom="{{ $map['min_zoom'] }}"
         data-max-zoom="{{ $map['max_zoom'] }}"
         data-tile-url="{{ $map['tile_url'] }}"
         data-attribution="{{ $map['attribution'] }}"
         data-latitude-input="#field-latitude"
         data-longitude-input="#field-longitude"></div>

    <div class="row g-2">
        <div class="col-sm-6">
            <x-form.input name="latitude" type="number" label="Latitude" step="0.0000001"
                          :value="$latitude" required id="field-latitude" />
        </div>
        <div class="col-sm-6">
            <x-form.input name="longitude" type="number" label="Longitude" step="0.0000001"
                          :value="$longitude" required id="field-longitude" />
        </div>
    </div>

    <p class="form-text mt-0">Map tiles need an internet connection. You can also type the coordinates directly.</p>
</div>
