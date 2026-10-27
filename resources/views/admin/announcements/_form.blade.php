{{-- Shared by the write and edit screens. --}}
<div class="row g-4">
    <div class="col-lg-8">
        <section class="vd-panel p-4">
            <x-form.input name="title" label="Title" :value="$announcement->title" required maxlength="200" />

            <x-form.input name="excerpt" label="Short summary" :value="$announcement->excerpt" maxlength="300"
                          help="One line shown on the home page and in the list. Leave empty to use the start of the announcement." />

            <div class="mb-3">
                <label class="form-label" for="field-body">Announcement</label>
                <textarea class="form-control @error('body') is-invalid @enderror"
                          id="field-body" name="body" rows="12" required
                          placeholder="What is happening, when, where, and who to contact.">{{ old('body', $announcement->body) }}</textarea>
                <div class="form-text">
                    Plain text. Line breaks are kept, and everything is shown exactly as typed, so no formatting codes are needed.
                </div>
                @error('body')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>
    </div>

    <div class="col-lg-4">
        <section class="vd-panel p-4 mb-4">
            <h2 class="h5 mb-2">Picture</h2>

            @if ($announcement->exists && filled($announcement->image))
                <img class="vd-gallery__main mb-2" src="{{ $announcement->image_url }}" alt="Current picture">
                <x-form.checkbox name="remove_image">Remove the current picture</x-form.checkbox>
            @endif

            <x-form.input name="image" type="file"
                          label="{{ $announcement->exists && filled($announcement->image) ? 'Replace picture' : 'Upload a picture' }}"
                          accept=".jpg,.jpeg,.png,.webp"
                          help="JPG, PNG, or WEBP up to 3 MB. Optional." />
        </section>

        <section class="vd-panel p-4">
            <h2 class="h5 mb-2">Publishing</h2>

            @if ($announcement->exists)
                <p class="mb-2"><x-status-badge :status="$announcement->status" /></p>
                @if ($announcement->published_at)
                    <p class="small text-muted-vd">
                        {{ $announcement->isPublished() ? 'Visible since' : 'Scheduled for' }}
                        {{ $announcement->published_at->format('M j, Y g:i A') }}.
                    </p>
                @endif
            @else
                <p class="text-muted-vd small">Saved as a draft first. You publish it from the list or from here afterwards.</p>
            @endif

            <x-form.input name="published_at" type="datetime-local" label="Publish date and time"
                          :value="old('published_at', $announcement->published_at?->format('Y-m-d\TH:i'))"
                          help="Leave empty to publish immediately. A future date keeps it hidden until then." />
        </section>
    </div>
</div>
