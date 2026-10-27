{{-- Shared by the add and edit destination screens. --}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-3">About this place</h2>

            <x-form.input name="name" label="Name" :value="$destination->name" required maxlength="150" />

            <x-form.select name="category_id" label="Category" :options="$categories"
                           :value="$destination->category_id" placeholder="Choose a category" required />

            <div class="mb-3">
                <label class="form-label" for="field-description">Description</label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="6" required
                          placeholder="What visitors will find, how to get there, and anything they should know.">{{ old('description', $destination->description) }}</textarea>
                <div class="form-text">Write only what the office can stand behind. Visitors read this as official information.</div>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>

        <section class="vd-panel p-4">
            <h2 class="h5 mb-3">Where it is</h2>

            <x-form.input name="address" label="Street, sitio, or landmark" :value="$destination->address" />

            <x-form.select name="barangay" label="Barangay" :options="array_combine($barangays, $barangays)"
                           :value="$destination->barangay" placeholder="Choose a barangay" />

            <x-coordinate-picker :latitude="$destination->latitude" :longitude="$destination->longitude" />
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-2">Cover photo</h2>
            <p class="form-text mt-0">Shown on the destinations list and the home page.</p>

            @if ($destination->exists && filled($destination->main_image))
                <img class="vd-gallery__main mb-2" src="{{ $destination->main_image_url }}" alt="Current cover photo">
                <x-form.checkbox name="remove_main_image">Remove the current cover photo</x-form.checkbox>
            @endif

            <x-form.input name="main_image" type="file"
                          label="{{ $destination->exists && filled($destination->main_image) ? 'Replace cover photo' : 'Upload cover photo' }}"
                          accept=".jpg,.jpeg,.png,.webp"
                          help="JPG, PNG, or WEBP up to 3 MB, at least 400x300 pixels." />
        </section>
    </div>
</div>
