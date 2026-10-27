{{--
    The interactive map, with markers loaded from the database.

    <x-visit-map :points="$points" class="vd-map--tall" />

    $points is a collection of arrays:
      id, type (resort|destination), name, subtitle, lat, lng, image, url, status

    locate  adds the "My location" button, the straight-line distances, and
            the road route. Off by default, because the office map is for
            checking where pins sit, not for travelling to them.
--}}
{{-- barangays is a separate list of {name, lat, lng, approximate}. Kept apart
     from $points because a barangay is a bearing, not a destination: it takes
     no distance reading and is never sent to the routing service. --}}
{{-- occupancy is the URL that answers "how full is each resort on these
     nights". Given only by the public map: the office map is for checking
     where pins sit, and colouring them by how busy a resort is would say
     nothing about that. Left out, the pins keep their one colour. --}}
{{-- fuel is a separate list of {id, name, brand, lat, lng} from
     OpenStreetMap. Kept apart from $points for the same reason the
     barangays are: somewhere you pass, not somewhere you are going, so it
     takes no distance reading and never reaches the routing service. --}}
@props([
    'points',
    'zoom' => null,
    'locate' => false,
    'barangays' => null,
    'occupancy' => null,
    'fuel' => null,
    'barangayShapes' => null,
    'checkpoints' => null,
])

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
        {{-- Shared geometry and formatting. Must come before map.js. --}}
        <script src="@vdasset('js/geo.js')"></script>
        <script src="@vdasset('js/map.js')"></script>
    @endpush
@endonce

@php($map = config('visitdasol.map'))

<div {{ $attributes->class('vd-map') }}
     data-visit-map
     data-points='@json($points)'
     @if (filled($barangays)) data-barangays='@json($barangays)' @endif
     @if (filled($fuel)) data-fuel='@json($fuel)' @endif
     @if (filled($barangayShapes)) data-barangay-shapes='@json($barangayShapes)' @endif
     @if (filled($checkpoints)) data-checkpoints='@json($checkpoints)' @endif
     @if ($occupancy) data-occupancy-url="{{ $occupancy }}" @endif
     data-center='@json($map['center'])'
     {{-- The municipal box, so the map can tell somebody their browser
          has placed them outside Dasol instead of silently measuring
          distances from the wrong town. --}}
     data-bounds='@json($map['bounds'])'
     data-zoom="{{ $zoom ?? $map['zoom'] }}"
     data-min-zoom="{{ $map['min_zoom'] }}"
     data-max-zoom="{{ $map['max_zoom'] }}"
     data-tile-url="{{ $map['tile_url'] }}"
     data-attribution="{{ $map['attribution'] }}"
     @if ($map['satellite_url'])
         data-satellite-url="{{ $map['satellite_url'] }}"
         data-satellite-attribution="{{ $map['satellite_attribution'] }}"
         data-satellite-max-zoom="{{ $map['satellite_max_zoom'] }}"

         {{-- Transparent reference overlays, drawn on the imagery so it
              carries road and place names. Only sent with the imagery:
              the street map already has its names drawn in. --}}
         @if ($map['satellite_labels_url'])
             data-satellite-labels-url="{{ $map['satellite_labels_url'] }}"
         @endif
         @if ($map['satellite_roads_url'])
             data-satellite-roads-url="{{ $map['satellite_roads_url'] }}"
         @endif
     @endif
     @if ($locate)
         data-locate
         @if ($map['routing_url'])
             data-routing-url="{{ $map['routing_url'] }}"
             {{-- Only when a route can be drawn at all: a height profile with
                  no road to lie along is nothing. --}}
             @if ($map['elevation_url']) data-route-profile-url="{{ route('routes.profile') }}" @endif
         @endif
         @if ($map['routing_table_url'])
             data-routing-table-url="{{ $map['routing_table_url'] }}"
             data-routing-table-limit="{{ $map['routing_table_limit'] }}"
         @endif
     @endif></div>
