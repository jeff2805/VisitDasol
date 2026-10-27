{{--
    Flash messages from redirects:
      return back()->with('success', 'Resort approved.');
    Keys: success, info, warning, error. Validation errors show a summary.
    Messages are escaped; never put HTML in them.
--}}
@php
    $flashTypes = [
        'success' => ['class' => 'alert-success', 'icon' => 'check-circle-fill'],
        'info' => ['class' => 'alert-info', 'icon' => 'info-circle-fill'],
        'warning' => ['class' => 'alert-warning', 'icon' => 'exclamation-triangle-fill'],
        'error' => ['class' => 'alert-danger', 'icon' => 'x-octagon-fill'],
    ];
@endphp

@foreach ($flashTypes as $key => $style)
    @if (session()->has($key))
        <div class="alert {{ $style['class'] }} alert-dismissible d-flex align-items-start gap-2" role="{{ $key === 'error' ? 'alert' : 'status' }}"
             @if ($key === 'success') data-autodismiss @endif>
            <i class="bi bi-{{ $style['icon'] }} mt-1" aria-hidden="true"></i>
            <div>{{ session($key) }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
@endforeach

@if (isset($errors) && $errors->any())
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            {{ $errors->count() === 1 ? 'Please fix the highlighted field and try again.' : 'Please fix the '.$errors->count().' highlighted fields and try again.' }}
        </div>
    </div>
@endif
