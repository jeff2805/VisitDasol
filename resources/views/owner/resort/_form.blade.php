{{--
    Shared by the register and edit screens.
    $resort is an empty Resort when registering.
--}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-3">About your resort</h2>

            <x-form.input name="name" label="Resort name" :value="$resort->name" required maxlength="150" />

            <div class="mb-3">
                <label class="form-label" for="field-description">
                    Description
                </label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="6" required
                          placeholder="Describe your rooms, the beach or grounds, and what guests should know.">{{ old('description', $resort->description) }}</textarea>
                <div class="form-text">Guests see this on your public page. At least a couple of sentences.</div>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>

        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-3">Where you are</h2>

            <x-form.input name="address" label="Street, sitio, or landmark" :value="$resort->address" required
                          help="House number, street, or sitio. The barangay and town are added automatically." />

            <x-form.select name="barangay" label="Barangay" :options="array_combine($barangays, $barangays)"
                           :value="$resort->barangay" placeholder="Choose a barangay" required />

            <x-coordinate-picker :latitude="$resort->latitude" :longitude="$resort->longitude" />
        </section>

        <section class="vd-panel p-4">
            <h2 class="h5 mb-3">Contact and times</h2>

            <div class="row">
                <div class="col-sm-6">
                    <x-form.input name="contact_number" type="tel" label="Contact number" :value="$resort->contact_number" required />
                </div>
                <div class="col-sm-6">
                    <x-form.input name="email" type="email" label="Booking email" :value="$resort->email" />
                </div>
            </div>

            <x-form.input name="website_url" type="url" label="Website or Facebook page" :value="$resort->website_url"
                          placeholder="https://" />

            <div class="row">
                <div class="col-sm-6">
                    <x-form.input name="check_in_time" type="time" label="Check-in time" required
                                  :value="old('check_in_time', $resort->check_in_time ? \Illuminate\Support\Carbon::parse($resort->check_in_time)->format('H:i') : '14:00')" />
                </div>
                <div class="col-sm-6">
                    <x-form.input name="check_out_time" type="time" label="Check-out time" required
                                  :value="old('check_out_time', $resort->check_out_time ? \Illuminate\Support\Carbon::parse($resort->check_out_time)->format('H:i') : '12:00')" />
                </div>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-2">Cover photo</h2>
            <p class="form-text mt-0">
                The first photo guests see. The tourism office asks for a cover photo before approving a listing.
            </p>

            @if ($resort->exists && filled($resort->main_image))
                <img class="vd-gallery__main mb-2" src="{{ $resort->main_image_url }}" alt="Current cover photo">
                <x-form.checkbox name="remove_main_image">Remove the current cover photo</x-form.checkbox>
            @endif

            <x-form.input name="main_image" type="file" label="{{ $resort->exists && filled($resort->main_image) ? 'Replace cover photo' : 'Upload cover photo' }}"
                          accept=".jpg,.jpeg,.png,.webp"
                          help="JPG, PNG, or WEBP up to 3 MB, at least 400x300 pixels." />
        </section>

        <section class="vd-panel p-4">
            <h2 class="h5 mb-2">Amenities</h2>
            <p class="form-text mt-0">
                Tick everything guests can use.
                {{-- The office used to keep this list and no longer does, so
                     the owner is told where the missing ones come from now.
                     A separate page rather than a form here: a second form
                     cannot sit inside this one, and sending it would throw
                     away whatever else had been typed and not yet saved. --}}
                Missing something?
                <a href="{{ route('owner.amenities.index') }}">Add it to the list</a>,
                then come back and tick it.
            </p>

            @error('amenities')
                <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
            @enderror

            <div class="row row-cols-1 row-cols-sm-2 g-1">
                @foreach ($amenities as $amenity)
                    <div class="col">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="amenities[]" value="{{ $amenity->id }}"
                                   id="amenity-{{ $amenity->id }}"
                                   @checked(in_array($amenity->id, old('amenities', $selectedAmenities), false))>
                            <label class="form-check-label" for="amenity-{{ $amenity->id }}">
                                @if ($amenity->icon)<i class="bi bi-{{ $amenity->icon }} me-1" aria-hidden="true"></i>@endif
                                {{ $amenity->name }}
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
