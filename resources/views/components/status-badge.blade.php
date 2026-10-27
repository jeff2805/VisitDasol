{{--
    Coloured status pill used for accounts, resorts, rooms, and bookings.

    <x-status-badge :status="$resort->status" />
--}}
@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = $status instanceof \BackedEnum && method_exists($status, 'label')
        ? $status->label()
        : ucfirst(str_replace('_', ' ', $value));
@endphp

<span {{ $attributes->class(['vd-badge', 'vd-badge--'.$value]) }}>{{ $label }}</span>
