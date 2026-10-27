{{--
    Checkbox with its own validation message, e.g. the terms confirmation.

    <x-form.checkbox name="terms" required>I confirm ...</x-form.checkbox>
--}}
@props(['name', 'checked' => false, 'required' => false, 'id' => null])

@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.', '_'], '-', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($key);
@endphp

<div class="form-check mb-3">
    <input type="checkbox"
           value="1"
           id="{{ $id }}"
           name="{{ $name }}"
           {{ $attributes->class(['form-check-input', 'is-invalid' => $hasError]) }}
           @checked(old($key, $checked))
           @required($required)
           @if ($hasError) aria-invalid="true" @endif>

    <label class="form-check-label" for="{{ $id }}">{{ $slot }}</label>

    @error($key)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
