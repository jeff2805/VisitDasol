{{--
    Labelled <select>.

    <x-form.select name="position" label="Your position" :options="$positions"
                   placeholder="Choose one" required />
--}}
@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'help' => null,
    'required' => false,
    'id' => null,
])

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.', '_'], '-', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($key);
    $selected = old($key, $value);
    $describedBy = collect([$help ? "{$id}-help" : null, $hasError ? "{$id}-error" : null])->filter()->implode(' ');
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $id }}">
        {{ $label }}
        @unless ($required)
            <span class="text-muted-vd fw-normal small">(optional)</span>
        @endunless
    </label>

    <select id="{{ $id }}"
            name="{{ $name }}"
            {{ $attributes->class(['form-select', 'is-invalid' => $hasError]) }}
            @required($required)
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($hasError) aria-invalid="true" @endif>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($help)
        <div class="form-text" id="{{ $id }}-help">{{ $help }}</div>
    @endif

    @error($key)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
