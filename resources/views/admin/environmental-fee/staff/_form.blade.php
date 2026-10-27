{{-- Shared by the add and edit staff screens.

     NO PASSWORD FIELD, AND THAT IS THE POINT. The office never chooses
     somebody else's password and never sees one: the account is made
     with a random secret nobody holds, and the person sets their own
     through a link that expires. --}}
<div class="row g-4">
    <div class="col-lg-6">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-3">Who they are</h2>

            <div class="row">
                <div class="col-sm-6">
                    <x-form.input name="first_name" label="First name" required maxlength="100"
                                  :value="$staff->first_name" />
                </div>
                <div class="col-sm-6">
                    <x-form.input name="last_name" label="Last name" required maxlength="100"
                                  :value="$staff->last_name" />
                </div>
            </div>

            <x-form.input name="email" type="email" label="Email address" required maxlength="255"
                          :value="$staff->email"
                          help="The invitation goes here, so it has to be an address they can open." />

            <x-form.input name="phone" label="Mobile number" :value="$staff->phone"
                          help="Optional. For the office to reach them about a shift or a shortfall." />

            <x-form.input name="employee_id" label="Employee number" :value="$staff->employee_id"
                          maxlength="40"
                          help="Optional, and the municipality's own reference. No two accounts may share one." />
        </section>
    </div>

    <div class="col-lg-6">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-1">What they do</h2>
            <p class="text-muted-vd small mb-3">
                This decides what they can reach. It can be changed later, and the
                change is recorded against your name.
            </p>

            @php($chosen = old('role', $staff->role?->value))

            <fieldset class="mb-3">
                <legend class="visually-hidden">Role</legend>

                @foreach ($roles as $value => $label)
                    <label class="vd-paychoice__option">
                        <input class="form-check-input" type="radio" name="role"
                               value="{{ $value }}" @checked($chosen === $value) required>
                        <span class="vd-paychoice__text">
                            <span class="vd-paychoice__name">{{ $label }}</span>
                            <span class="vd-paychoice__detail">{{ $descriptions[$value] }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>

            @error('role')
                <p class="text-danger small">{{ $message }}</p>
            @enderror

            <h3 class="h6 mt-4 mb-1">Checkpoints</h3>
            <p class="text-muted-vd small mb-2">
                Only a collector is put on a gate. Which gate is a separate decision
                from what post they hold, so somebody can be moved without being
                given a new account.
            </p>

            @if ($checkpoints->isEmpty())
                <p class="form-text mb-0">
                    No checkpoint is switched on yet.
                    <a href="{{ route('admin.environmental-fee.checkpoints.index') }}">Add one</a>
                    before putting anybody on a gate.
                </p>
            @else
                @php($ticked = old('checkpoints', $assigned))

                <div class="vd-staff-checkpoints">
                    @foreach ($checkpoints as $checkpoint)
                        <label class="vd-staff-checkpoints__item">
                            <input class="form-check-input" type="checkbox" name="checkpoints[]"
                                   value="{{ $checkpoint->id }}"
                                   @checked(in_array((int) $checkpoint->id, array_map('intval', (array) $ticked), true))>
                            <span>
                                {{ $checkpoint->name }}
                                <span class="d-block small text-muted-vd">Brgy. {{ $checkpoint->barangay }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                @error('checkpoints')
                    <p class="text-danger small mt-2">{{ $message }}</p>
                @enderror
            @endif
        </section>
    </div>
</div>
