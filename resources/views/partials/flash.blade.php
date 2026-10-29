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

{{--
    Validation errors.

    SAY WHAT IS WRONG, NOT HOW MANY THINGS ARE WRONG.

    This used to read "Please fix the 4 highlighted fields and try
    again." and stop there, trusting every field to mark itself. When
    one does not — a nested key like extras.7, which no @error('extras')
    block matches, or simply a field below the fold — the guest is told
    to correct something and given no way to find it. A booking form
    that says "4 fields" while highlighting none is not an error
    message, it is a riddle.

    So the messages are listed. The per-field marks still do the close
    work of pointing at the box; this makes sure nothing can be counted
    and then hidden.
--}}
@if (isset($errors) && $errors->any())
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            @if ($errors->count() === 1)
                {{ $errors->first() }}
            @else
                <strong class="d-block">Please fix these {{ $errors->count() }} things and try again.</strong>
                <ul class="mb-0 mt-1 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
