{{--
    The walk-in form, shared by recording a new one and correcting a
    returned one. One file, because a correction must ask exactly what the
    original asked — two copies would drift and the office would start
    seeing fields on resubmissions that new records never carried.

    $record is null when recording, and the booking when correcting.

    NOTE ON WHAT IS REQUIRED. Only the guest's name, number, room and dates
    are required to SAVE. The tourism columns are not, on purpose: a resort
    should be able to write a guest down at a busy desk and finish the
    paperwork afterwards. WalkInSubmissionService is what refuses to SEND an
    incomplete record, and the draft screen says what is still missing.
--}}
{{-- An include, not a component, so the variables arrive from the parent
     view rather than through a props directive. $record is absent when
     recording a new walk-in and is the booking when correcting one.

     TWO RULES FOR THIS FILE, BOTH LEARNED THE HARD WAY:

     1. NEVER WRITE A DIRECTIVE NAME AFTER AN AT-SIGN IN A COMMENT HERE.
        Blade compiles directives inside comments: naming the props one in
        this very comment compiled it, and the page died on a syntax error
        in generated code.

     2. ONE BLOCK-FORM PHP DIRECTIVE, NEVER THE INLINE PARENTHESISED FORM.
        Blade's raw-block pass matches lazily to the first closing endphp,
        so an inline one earlier in a file that also uses the block form
        swallows everything between the two — the CSRF field and the method
        spoof included. Both variables are therefore set together, here. --}}
@php
    $record = $record ?? null;
    $type = old('booking_type', $record?->booking_type?->value ?? \App\Enums\BookingType::Overnight->value);
@endphp

@csrf
@if ($record)
    @method('PUT')
@endif

<div class="vd-panel p-4 mb-3">
    <h2 class="h5 mb-3">The guest</h2>

    <div class="row">
        <div class="col-12 col-md-6">
            <x-form.input name="contact_name" label="Guest full name" required
                          :value="$record?->contact_name"
                          help="As they gave it at the desk." />
        </div>
        <div class="col-12 col-md-6">
            <x-form.input name="contact_phone" label="Contact number" required
                          :value="$record?->contact_phone" />
        </div>
        <div class="col-12 col-md-6">
            <x-form.input name="contact_email" type="email" label="Email address"
                          :value="$record?->contact_email" />
        </div>
        <div class="col-12 col-md-6">
            <x-form.input name="guest_address" label="Address"
                          :value="$record?->guest_address"
                          help="Their home address, for your own register." />
        </div>
    </div>
</div>

<div class="vd-panel p-4 mb-3">
    <h2 class="h5 mb-1">The party</h2>
    <p class="text-muted-vd small">
        PWD guests are counted among the adults and children, not on top of them.
    </p>

    <div class="row">
        <div class="col-6 col-md-3">
            <x-form.input name="adults" type="number" label="Adults" required min="1" max="99"
                          :value="$record?->adults ?? 1" />
        </div>
        <div class="col-6 col-md-3">
            <x-form.input name="children" type="number" label="Children" min="0" max="99"
                          :value="$record?->children ?? 0" />
        </div>
        <div class="col-6 col-md-3">
            <x-form.input name="pwd_guests" type="number" label="PWD guests" min="0" max="99"
                          :value="$record?->pwd_guests ?? 0" />
        </div>
        <div class="col-6 col-md-3">
            <x-form.input name="rooms_booked" type="number" label="Rooms taken" required min="1" max="50"
                          :value="$record?->rooms_booked ?? 1" />
        </div>
    </div>
</div>

<div class="vd-panel p-4 mb-3">
    <h2 class="h5 mb-1">What they booked</h2>
    <p class="text-muted-vd small">
        A day or night tour is one date. The room is still held for that whole date.
    </p>

    <div class="row">
        <div class="col-12 col-md-6">
            <x-form.select name="room" label="Room" required
                           :value="$record?->room_id"
                           placeholder="Choose a room"
                           :options="$rooms->pluck('name', 'id')->all()" />
        </div>

        <div class="col-12 col-md-6">
            <x-form.select name="booking_type" label="Booking type" required
                           :value="$type" :options="$types" />
        </div>

        <div class="col-12 col-md-6">
            <x-form.input name="check_in_date" type="date" label="Check-in date" required
                          :value="$record?->check_in_date?->toDateString()"
                          help="A date already past is fine: a walk-in is often written down after the guest arrives." />
        </div>

        <div class="col-12 col-md-6">
            <x-form.input name="check_out_date" type="date" label="Check-out date"
                          :value="$record?->check_out_date?->toDateString()"
                          help="Leave blank for a day or night tour." />
        </div>

        <div class="col-6 col-md-3">
            <x-form.input name="check_in_time" type="time" label="Check-in time"
                          :value="$record?->check_in_time" />
        </div>

        <div class="col-6 col-md-3">
            <x-form.input name="check_out_time" type="time" label="Check-out time"
                          :value="$record?->check_out_time" />
        </div>
    </div>
</div>

<div class="vd-panel p-4 mb-3">
    <h2 class="h5 mb-1">For the tourism report</h2>
    <p class="text-muted-vd small">
        These are the figures the Municipal Tourism Office files with the Department of
        Tourism each month. You can save without them, but the record cannot be sent
        until they are filled in.
    </p>

    <div class="row">
        <div class="col-6 col-md-3">
            <x-form.input name="guests_male" type="number" label="Male guests" min="0" max="99"
                          :value="$record?->guests_male" />
        </div>
        <div class="col-6 col-md-3">
            <x-form.input name="guests_female" type="number" label="Female guests" min="0" max="99"
                          :value="$record?->guests_female" />
        </div>
        <div class="col-12 col-md-6">
            <x-form.select name="origin" label="Travelling from"
                           :value="$record?->origin?->value"
                           placeholder="Choose one"
                           :options="$origins" />
        </div>
    </div>

    <div class="form-text">
        The male and female counts must add up to the whole party.
    </div>
</div>

<div class="vd-panel p-4 mb-3">
    <h2 class="h5 mb-3">Anything else</h2>

    <label class="form-label" for="field-special-requests">
        Special requests
        <span class="text-muted-vd fw-normal small">(optional)</span>
    </label>
    <textarea id="field-special-requests" name="special_requests" rows="3"
              class="form-control @error('special_requests') is-invalid @enderror"
              maxlength="1000">{{ old('special_requests', $record?->special_requests) }}</textarea>
    @error('special_requests')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
