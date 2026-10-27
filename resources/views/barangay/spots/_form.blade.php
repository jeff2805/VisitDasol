{{-- Shared by the add and edit screens for a barangay official.

     No barangay field: it comes from the official's own account. An official
     speaks for one barangay, and letting them choose another on this form
     would be the whole point of that rule gone. --}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-3">About this place</h2>

            <x-form.input name="name" label="Name of the place" :value="$spot->name" required maxlength="150"
                          help="What people in the barangay actually call it." />

            <x-form.select name="category_id" label="What kind of place is it?" :options="$categories"
                           :value="$spot->category_id" placeholder="Choose one" required />

            <div class="mb-0">
                <label class="form-label" for="field-description">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="6" required
                          placeholder="What a visitor will find, how to get there, the best time to go, and anything they should know before setting out.">{{ old('description', $spot->description) }}</textarea>
                <div class="form-text">
                    Write what you know to be true. The tourism office reads this before
                    anyone else sees it, and visitors will treat it as official.
                </div>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>

        <section class="vd-panel p-4">
            <h2 class="h5 mb-1">Where it is</h2>
            <p class="form-text mt-0 mb-3">
                This spot is recorded under <strong>Barangay {{ auth()->user()->barangay }}</strong>,
                your barangay.
            </p>

            <x-form.input name="address" label="Sitio, road, or nearest landmark" :value="$spot->address"
                          help="How someone from outside would be told to find it." />

            <x-coordinate-picker :latitude="$spot->latitude" :longitude="$spot->longitude" />
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-2">Photo</h2>
            <p class="form-text mt-0">
                One good photo of the place. A spot with no photo is far less likely
                to be visited, and far more likely to be sent back.
            </p>

            @if ($spot->exists && filled($spot->main_image))
                <img class="vd-gallery__main mb-2" src="{{ $spot->main_image_url }}" alt="Current photo">
                <x-form.checkbox name="remove_main_image">Remove the current photo</x-form.checkbox>
            @endif

            <x-form.input name="main_image" type="file"
                          label="{{ $spot->exists && filled($spot->main_image) ? 'Replace photo' : 'Upload photo' }}"
                          accept=".jpg,.jpeg,.png,.webp"
                          help="JPG, PNG, or WEBP up to 3 MB, at least 400x300 pixels." />
        </section>
    </div>
</div>
