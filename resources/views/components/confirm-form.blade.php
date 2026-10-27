{{--
    A button that opens a confirmation dialog containing the real form.
    Use it for anything that changes a status: approve, reject, deactivate.

    <x-confirm-form id="approve-owner"
                    :action="route('admin.owner-applications.approve', $owner)"
                    method="PATCH"
                    title="Approve this owner account?"
                    message="They will be able to sign in and submit their resort."
                    trigger="Approve"
                    confirm="Yes, approve"
                    variant="primary" />

    Extra fields (such as a reason box) go in the default slot and appear
    inside the dialog, above the buttons.
--}}
@props([
    'id',
    'action',
    'method' => 'PATCH',
    'title',
    'message' => null,
    'trigger',
    'confirm' => 'Confirm',
    'variant' => 'primary',
    'triggerClass' => null,
    'icon' => null,
])

<button type="button"
        {{ $attributes->class(['btn', $triggerClass ?? 'btn-'.$variant]) }}
        data-bs-toggle="modal"
        data-bs-target="#{{ $id }}">
    @if ($icon)<i class="bi bi-{{ $icon }} me-1" aria-hidden="true"></i>@endif{{ $trigger }}
</button>

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ $action }}" data-submit-once>
            @csrf
            @method($method)

            <div class="modal-header">
                <h2 class="modal-title h5" id="{{ $id }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                @if ($message)
                    <p @class(['mb-0' => $slot->isEmpty()])>{{ $message }}</p>
                @endif
                {{ $slot }}
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-{{ $variant }}">{{ $confirm }}</button>
            </div>
        </form>
    </div>
</div>
