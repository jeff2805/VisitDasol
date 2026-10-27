{{--
    Dashboard sidebar link that is safe to use before its route exists.

    <x-sidebar-link route="owner.rooms.index" match="owner.rooms.*" icon="door-open">Rooms</x-sidebar-link>

    - icon:   Bootstrap Icons name without the "bi-" prefix
    - count:  optional number shown as a badge (hidden when zero)
--}}
@props(['route', 'icon', 'match' => null, 'count' => null, 'params' => []])

@php
    $exists = Route::has($route);
    $active = $exists && request()->routeIs($match ?? $route);
@endphp

<li>
    <a @class(['vd-sidebar__link', 'active' => $active, 'disabled' => ! $exists])
       href="{{ $exists ? route($route, $params) : '#' }}"
       @if ($active) aria-current="page" @endif
       @unless ($exists) aria-disabled="true" tabindex="-1" @endunless>
        <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
        <span>{{ $slot }}</span>
        @if ($exists && (int) $count > 0)
            <span class="vd-sidebar__count">{{ $count }}<span class="visually-hidden"> waiting</span></span>
        @elseif (! $exists)
            <span class="vd-sidebar__soon">Soon</span>
        @endif
    </a>
</li>
