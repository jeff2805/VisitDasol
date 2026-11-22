{{--
    The dialog behind one row's Edit button on the accreditation list.

    Separate from the table so the table stays readable: six form fields
    inlined into a loop buried the row markup it belongs to.
--}}
@props(['record', 'statuses'])

@php($id = 'accredited-'.$record->id)

<div class="modal fade" id="{{ $id }}" tabindex="-1"
     aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST"
              action="{{ route('admin.accredited-resorts.update', $record) }}" data-submit-once>
            @csrf
            @method('PUT')

            <div class="modal-header">
                <h2 class="modal-title h5" id="{{ $id }}-title">Edit accreditation record</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <x-form.input :name="'resort_name'" label="Resort name" :value="$record->resort_name"
                              :id="$id.'-name'" required maxlength="200" />

                <x-form.input :name="'owner_name'" label="Owner name" :value="$record->owner_name"
                              :id="$id.'-owner'" maxlength="200" />

                <x-form.input :name="'barangay'" label="Barangay" :value="$record->barangay"
                              :id="$id.'-barangay'" maxlength="120" />

                <x-form.input :name="'accreditation_no'" label="Accreditation number"
                              :value="$record->accreditation_no" :id="$id.'-no'" maxlength="60" />

                <x-form.select :name="'accreditation_status'" label="Accreditation status"
                               :options="$statuses" :value="$record->accreditation_status->value"
                               :id="$id.'-status'" required />

                <div class="mb-3">
                    <label class="form-label" for="{{ $id }}-valid">
                        Valid until <span class="text-muted-vd fw-normal small">(optional)</span>
                    </label>
                    <input type="date" class="form-control" id="{{ $id }}-valid" name="valid_until"
                           value="{{ $record->valid_until?->toDateString() }}">
                    <div class="form-text">
                        Leave blank if the accreditation has no expiry on file.
                    </div>
                </div>
            </div>

            <div class="modal-footer justify-content-between">
                {{-- Removing a record does NOT remove the findings already
                     made against it: applications keep the accreditation
                     number they matched. Said here because "delete" in a
                     register usually means the history goes too. --}}
                <button type="submit" class="btn btn-outline-danger"
                        form="{{ $id }}-delete">
                    Remove from list
                </button>

                <span>
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </span>
            </div>
        </form>

        {{-- Outside the edit form, because a form cannot be nested inside
             another one — the browser drops the inner one silently. --}}
        <form id="{{ $id }}-delete" method="POST"
              action="{{ route('admin.accredited-resorts.destroy', $record) }}" class="d-none">
            @csrf
            @method('DELETE')
        </form>
    </div>
</div>
