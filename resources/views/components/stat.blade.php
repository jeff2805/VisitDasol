{{--
    One figure on a dashboard, with its label.

    <x-stat :value="$published" label="On the site" note="Visitors can see these." />
    <x-stat :value="$stats['upcoming']" label="Upcoming stays" icon="calendar-check" />

    Before this existed the same tile was written five different ways across
    eight files — some with a panel behind it and some without, some putting
    the label above the value and some below, and one using a note class that
    had no style rule at all. They are all this now, so they cannot drift
    apart again.

    The value comes first because that is what the stylesheet is built for:
    .vd-stat__label carries a top margin that only makes sense underneath.

    OPTIONAL: href makes the whole tile the link, and alert paints it red.

    A figure somebody has to act on is a different thing from a figure they
    only read. The owner's waiting-requests tile was the second kind — a
    number, and the page below it the only way through to the list. Rather
    than build a separate clickable card beside it and let the two drift,
    the tile itself grew the two things it was missing.

    href renders an <a> rather than a <div>, which is what makes it reachable
    by keyboard and announced as a link; nothing here is a click handler on a
    div. The classes and the inside are identical either way.
--}}
{{-- label may be given as an attribute for the usual case, or as a slot when
     it needs markup of its own (the setup check colours an icon inside it). --}}
@props([
    'value',
    'label' => null,
    'note' => null,
    'icon' => null,
    'href' => null,
    'alert' => false,
])

@php
    $tag = filled($href) ? 'a' : 'div';

    $classes = $attributes->class([
        'vd-panel',
        'vd-stat',
        'h-100',
        'vd-stat--with-icon' => filled($icon),
        'vd-stat--link' => filled($href),
        'vd-stat--alert' => (bool) $alert,
    ]);
@endphp

<{{ $tag }} {{ $classes }} @if (filled($href)) href="{{ $href }}" @endif>
    @if (filled($icon))
        <span class="vd-feature__icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
    @endif

    <div class="min-w-0">
        <div class="vd-stat__value">{{ $value }}</div>
        <div class="vd-stat__label">{{ $label }}</div>

        @if (filled($note))
            <p class="vd-stat__note">{{ $note }}</p>
        @endif
    </div>
</{{ $tag }}>
