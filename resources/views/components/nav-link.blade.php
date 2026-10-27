{{--
    Top navigation link that is safe to use before its route exists.

    <x-nav-link route="resorts.index" match="resorts.*">Resorts</x-nav-link>

    - route:  named route to link to
    - match:  route name pattern(s) that mark the link as active (defaults to route)
    - If the route is not registered yet (a later phase), the link renders disabled.
--}}
@props(['route', 'match' => null, 'params' => []])

@php
    $exists = Route::has($route);
    $active = $exists && request()->routeIs($match ?? $route);
@endphp

<li class="nav-item">
    <a @class(['nav-link', 'active' => $active, 'disabled' => ! $exists])
       href="{{ $exists ? route($route, $params) : '#' }}"
       @if ($active) aria-current="page" @endif
       @unless ($exists) aria-disabled="true" tabindex="-1" title="Available in a later phase" @endunless
       {{ $attributes }}>{{ $slot }}</a>
</li>
