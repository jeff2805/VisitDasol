{{--
    Faint palm silhouettes for the edge of a tinted section.

        <x-palm-fronds side="left" />

    Inline SVG rather than an image file: it inherits `color` from the CSS, so
    one shape works on any band. Decorative, so it is hidden from screen readers.
--}}
@props(['side' => 'left'])

<svg {{ $attributes->class(['vd-fronds', 'vd-fronds--'.$side]) }}
     viewBox="0 0 240 600" preserveAspectRatio="xMidYMid slice"
     aria-hidden="true" focusable="false">
    {{-- Each frond is one stem with blades stepping down either side of it. --}}
    <g fill="currentColor">
        <path d="M18 92c44 6 86 30 114 66-34-12-70-14-104-6 30 4 58 16 82 34-30-6-60-4-88 6 34 2 66 14 92 34-46-4-90 8-126 34 8-62 22-122 30-168z" />
        <path d="M6 250c52 14 96 46 126 90-38-18-78-26-118-22 34 10 64 28 88 52-34-12-68-14-100-8 38 8 72 26 98 52-50-12-98-4-138 20 12-68 30-132 44-184z" />
        <path d="M30 430c46 18 84 50 108 92-32-18-68-28-104-28 28 12 52 30 72 54-28-14-58-20-88-18 32 12 60 30 82 54-42-16-86-14-126 6 16-58 36-112 56-160z" />
    </g>
</svg>
