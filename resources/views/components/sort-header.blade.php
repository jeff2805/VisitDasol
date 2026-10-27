{{--
    A table heading that sorts the list.

    <x-sort-header :sort="$sort" column="name">Name</x-sort-header>

    The whole thing is a link, so sorting needs no JavaScript and works under
    the site's Content-Security-Policy, which blocks inline script. aria-sort
    carries the current state for a screen reader; the arrow carries it for
    everyone else, so it never rests on the icon alone.

    $sort is an App\Support\TableSort, which also decides what each link asks
    for next and keeps the request's own column list honest.
--}}
@props(['sort', 'column', 'align' => null])

@php
    $on = $sort->isOn($column);
    $aria = $sort->ariaSort($column);

    // Which arrow: the direction in use, or a neutral pair when this column is
    // not the one sorting the list.
    $icon = match (true) {
        ! $on => 'chevron-expand',
        $sort->direction === 'asc' => 'caret-up-fill',
        default => 'caret-down-fill',
    };
@endphp

<th scope="col" @if ($aria) aria-sort="{{ $aria }}" @endif @class(['text-end' => $align === 'end'])>
    {{-- page is dropped: re-sorting a list and staying on page 5 of the old
         order shows the reader somewhere they did not ask to be. --}}
    <a @class(['vd-sort', 'is-on' => $on])
       href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $sort->nextDirection($column), 'page' => null]) }}">
        <span>{{ $slot }}</span>
        <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
    </a>
</th>
