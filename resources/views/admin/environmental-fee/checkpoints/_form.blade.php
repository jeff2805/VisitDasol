{{-- Shared by the add and edit checkpoint screens. --}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4">
            <x-form.input name="name" label="Checkpoint name" :value="$checkpoint->name" required
                          maxlength="120" help="For example: Tambobong beach road entrance." />

            <div class="mb-3">
                <label class="form-label" for="field-barangay">Barangay</label>
                <select class="form-select @error('barangay') is-invalid @enderror"
                        id="field-barangay" name="barangay" required>
                    <option value="" disabled @selected(! old('barangay', $checkpoint->barangay))>Choose one</option>
                    @foreach (config('visitdasol.barangays') as $barangay)
                        <option value="{{ $barangay }}"
                                @selected(old('barangay', $checkpoint->barangay) === $barangay)>
                            {{ $barangay }}
                        </option>
                    @endforeach
                </select>
                @error('barangay')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-0">
                <label class="form-label" for="field-description">
                    Description <span class="text-muted-vd fw-normal small">(optional)</span>
                </label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="3"
                          placeholder="Where exactly it is, and when it is manned.">{{ old('description', $checkpoint->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-1">Where it is</h2>
            <p class="text-muted-vd small mb-3">
                Pick the point on the map. It has to be inside Dasol.
            </p>

            {{-- The same picker the destination and resort forms use, so
                 an officer who has placed one pin has placed them all. --}}
            <x-coordinate-picker :latitude="old('latitude', $checkpoint->latitude)"
                                 :longitude="old('longitude', $checkpoint->longitude)" />
        </section>
    </div>
</div>
