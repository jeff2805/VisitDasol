{{--
    Labelled input with old value, validation styling, and help text.

    <x-form.input name="email" type="email" label="Email address" required
                  autocomplete="username" help="We never share it." />

    Password and file inputs never re-display a value.
    Password inputs also get a show/hide button; see [data-password-toggle]
    in public/js/app.js.
--}}
{{-- icon: a Bootstrap Icon name shown before the label, for the few fields
     where it helps the eye find the right box at a glance — the sign-in form,
     where there are only two. Decorative, so it is hidden from screen readers:
     the label already says what the field is. --}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    'id' => null,
    'icon' => null,
])

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.', '_'], '-', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($key);
    $keepsValue = ! in_array($type, ['password', 'file'], true);
    $isPassword = $type === 'password';
    $describedBy = collect([$help ? "{$id}-help" : null, $hasError ? "{$id}-error" : null])->filter()->implode(' ');
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $id }}">
        @if ($icon)
            <i class="bi bi-{{ $icon }} vd-form-label__icon" aria-hidden="true"></i>
        @endif
        {{ $label }}
        @unless ($required)
            <span class="text-muted-vd fw-normal small">(optional)</span>
        @endunless
    </label>

    {{-- A password sits in a wrapper so the show/hide button can be placed
         inside its right edge. The wrapper would otherwise come between the
         field and its message, which is how Bootstrap decides to reveal one,
         so the wrapper carries the state instead (see .vd-password--invalid).

         A field with an icon gets the same wrapper for the same reason: the
         icon sits inside the field's left edge. --}}
    @if ($isPassword || $icon)
        <div @class([
            'vd-field',
            'vd-field--icon' => filled($icon),
            'vd-password' => $isPassword,
            'vd-password--invalid' => $isPassword && $hasError,
        ])>
    @endif

    @if ($icon)
        {{-- Repeats the icon already beside the label, so it says nothing new
             and is hidden from assistive technology. --}}
        <i class="bi bi-{{ $icon }} vd-field__icon" aria-hidden="true"></i>
    @endif

    <input type="{{ $type }}"
           id="{{ $id }}"
           name="{{ $name }}"
           @if ($keepsValue) value="{{ old($key, $value) }}" @endif
           {{ $attributes->class(['form-control', 'is-invalid' => $hasError]) }}
           @required($required)
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           @if ($hasError) aria-invalid="true" @endif>

    @if ($isPassword)
            {{-- The icon says what the password IS, not what the button
                 would do — the same way the rest of this project reads
                 an eye: bi-eye-slash beside "hidden from the public",
                 bi-eye beside "this listing is live". A password
                 starting as dots therefore starts crossed out.

                 The name stays "Show password" in both states and
                 aria-pressed carries the change. A name that flipped to
                 "Hide password" WHILE pressed announced as "Hide
                 password, pressed" — which says hiding is on at the
                 very moment the password is on screen. --}}
            <button type="button" class="vd-password__toggle"
                    data-password-toggle aria-controls="{{ $id }}" aria-pressed="false">
                <i class="bi bi-eye-slash" aria-hidden="true"></i>
                <span class="visually-hidden">Show password</span>
            </button>
    @endif

    @if ($isPassword || $icon)
        </div>
    @endif

    @if ($help)
        <div class="form-text" id="{{ $id }}-help">{{ $help }}</div>
    @endif

    @error($key)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
