{{--
    Page heading with optional subtitle and action buttons.

    <x-page-header title="Rooms" subtitle="Room types guests can book.">
        <a class="btn btn-primary" href="...">Add room type</a>
    </x-page-header>
--}}
@props(['title', 'subtitle' => null])

<div {{ $attributes->class('vd-page-header') }}>
    <div class="min-w-0">
        <h1 class="vd-page-header__title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="vd-page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="d-flex flex-wrap gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
