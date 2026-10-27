{{--
    Empty list message that tells the user what to do next.

    <x-empty-state icon="door-open" title="No room types yet"
                   message="Add a room type so guests can book it.">
        <a class="btn btn-primary" href="...">Add room type</a>
    </x-empty-state>
--}}
@props(['icon' => 'inbox', 'title', 'message' => null])

<div {{ $attributes->class('vd-empty') }}>
    <span class="vd-empty__icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
    <h2 class="vd-empty__title">{{ $title }}</h2>
    @if ($message)
        <p class="vd-empty__text">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
