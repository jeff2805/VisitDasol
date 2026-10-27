{{--
    A star rating, shown as stars and also said in words.

    <x-stars :rating="4.6" :count="84" />
    <x-stars :rating="$review->rating" />

    The number is the accessible answer: screen readers get "4.6 out of 5",
    not five separate icon names. The stars themselves are decorative.
--}}
@props([
    'rating' => null,
    'count' => null,
    'showNumber' => true,
])

@php
    $value = $rating === null ? null : round((float) $rating, 1);
    $filled = $value === null ? 0 : (int) floor($value);
    // A half star from .3 upwards, so 4.5 does not read as plain 4.
    $half = $value !== null && ($value - $filled) >= 0.3;
@endphp

@if ($value === null || (int) $count === 0 && $rating === null)
    <span {{ $attributes->class('vd-stars vd-stars--none') }}>Not yet reviewed</span>
@else
    <span {{ $attributes->class('vd-stars') }}>
        <span class="vd-stars__icons" aria-hidden="true">
            @for ($i = 1; $i <= 5; $i++)
                @if ($i <= $filled)
                    <i class="bi bi-star-fill"></i>
                @elseif ($i === $filled + 1 && $half)
                    <i class="bi bi-star-half"></i>
                @else
                    <i class="bi bi-star"></i>
                @endif
            @endfor
        </span>

        @if ($showNumber)
            <span class="vd-stars__value">{{ number_format($value, 1) }}</span>
        @endif

        @if ($count !== null)
            <span class="vd-stars__count">({{ number_format((int) $count) }})</span>
        @endif

        <span class="visually-hidden">
            {{ number_format($value, 1) }} out of 5
            @if ($count !== null)
                from {{ $count }} {{ Str::plural('review', (int) $count) }}
            @endif
        </span>
    </span>
@endif
