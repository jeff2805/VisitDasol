{{--
    A box that scrolls sideways when its contents do not fit.

    <x-scroll-region label="Availability calendar">
        <table …>
    </x-scroll-region>

    On a phone a wide table has to scroll rather than shrink its text to
    nothing. A plain overflow box scrolls with a finger and a trackpad and with
    nothing else: a keyboard cannot reach it, so the columns past the right edge
    are simply unreachable, and a screen reader is told nothing about it either.

    tabindex makes it focusable, role and aria-label make it a named landmark,
    and the stylesheet draws an outline when it is focused so somebody tabbing
    through can see what the arrow keys will now move.

    <x-data-table> uses this, so every table on an office page gets it; this
    component exists for the handful of tables that are not on that shell —
    the availability calendar, the reports, and the dashboard boards.
--}}
@props(['label'])

<div {{ $attributes->class('table-responsive') }} tabindex="0" role="region" aria-label="{{ $label }}">
    {{ $slot }}
</div>
