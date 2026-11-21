@extends('layouts.admin')

@section('title', $section->label())

@section('content')
    @php
        use App\Enums\AboutSection as S;
        $imageUrl = fn (?string $path): ?string => filled($path) ? Storage::disk('public')->url($path) : null;
    @endphp

    <x-page-header :title="$section->label()" :subtitle="$section->blurb()">
        <a class="btn btn-light border" href="{{ route('admin.about.index') }}">Back</a>
        <x-external-link class="btn btn-light border" :href="route('admin.about.preview')">
            <i class="bi bi-eye me-1" aria-hidden="true"></i>Preview
        </x-external-link>
    </x-page-header>

    @if ($row->hasUnpublishedChanges())
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-pencil mt-1" aria-hidden="true"></i>
            <span>This section has unpublished changes. Visitors still see the previous version.</span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.about.update', $section->value) }}"
          enctype="multipart/form-data" data-submit-once>
        @csrf
        @method('PUT')

        <div class="vd-panel p-4 mb-3">
            {{-- HERO ------------------------------------------------------ --}}
            @if ($section === S::Hero)
                <x-form.input name="label" label="Small label above the heading" required
                              :value="$values['label']" help="Shown in capitals, e.g. ABOUT DASOL." />
                <x-form.input name="heading" label="Main heading" required :value="$values['heading']" />

                <label class="form-label" for="subheading">Subtitle</label>
                <textarea class="form-control @error('subheading') is-invalid @enderror"
                          id="subheading" name="subheading" rows="2" maxlength="300">{{ old('subheading', $values['subheading']) }}</textarea>
                @error('subheading')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif

            {{-- MUNICIPALITY / HERITAGE ------------------------------------ --}}
            @if (in_array($section, [S::Municipality, S::Heritage], true))
                <x-form.input name="heading" label="Section heading" required :value="$values['heading']" />

                <label class="form-label" for="body">Text</label>
                <textarea class="form-control @error('body') is-invalid @enderror"
                          id="body" name="body" rows="8" maxlength="4000">{{ old('body', $values['body']) }}</textarea>
                <div class="form-text">Leave a blank line between paragraphs.</div>
                @error('body')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @endif

            {{-- HISTORY: an ordered list of paragraphs --------------------- --}}
            @if ($section === S::History)
                <x-form.input name="heading" label="Section heading" required :value="$values['heading']" />

                <label class="form-label">Paragraphs</label>
                <p class="text-muted-vd small">
                    Each box is one paragraph. Empty a box and save to remove it;
                    the order here is the order on the page.
                </p>

                @foreach (old('paragraphs', $values['paragraphs']) as $index => $paragraph)
                    <textarea class="form-control mb-2" name="paragraphs[{{ $index }}]"
                              rows="4" maxlength="4000">{{ $paragraph }}</textarea>
                @endforeach

                {{-- One always-empty box, so adding a paragraph needs no
                     JavaScript and works with the keyboard alone. --}}
                <textarea class="form-control mb-2" name="paragraphs[{{ count(old('paragraphs', $values['paragraphs'])) }}]"
                          rows="3" maxlength="4000" placeholder="Add another paragraph…"></textarea>

                @error('paragraphs')<div class="text-danger small">{{ $message }}</div>@enderror
                @error('paragraphs.*')<div class="text-danger small">{{ $message }}</div>@enderror
            @endif

            {{-- DESTINATIONS: real records, chosen and ordered ------------- --}}
            @if ($section === S::Destinations)
                <x-form.input name="heading" label="Section heading" required :value="$values['heading']" />
                <x-form.input name="subheading" label="Subtitle" :value="$values['subheading']" />

                <label class="form-label">Which destinations appear, and in what order</label>
                <p class="text-muted-vd small">
                    These are your existing destination records. Unticking one removes it from the
                    About page only — the destination itself is untouched. With none ticked, the
                    page shows your featured destinations.
                </p>

                @php
                    $chosen = old('destination_ids', $values['destination_ids']) ?? [];
                    // The stored list IS the order, so a place's position is
                    // simply where it sits in it.
                    $positionOf = array_flip(array_values($chosen));
                @endphp

                @forelse ($destinations as $destination)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="form-check mb-0 flex-grow-1">
                            <input class="form-check-input" type="checkbox"
                                   id="dest-{{ $destination->id }}"
                                   name="destination_ids[]" value="{{ $destination->id }}"
                                   @checked(in_array($destination->id, $chosen))>
                            <label class="form-check-label" for="dest-{{ $destination->id }}">
                                {{ $destination->name }}
                                <span class="text-muted-vd small">{{ $destination->barangay }}</span>
                            </label>
                        </div>

                        {{-- A number rather than drag and drop: it needs no
                             JavaScript, works from the keyboard, and still
                             works on a page whose scripts failed to load. --}}
                        <label class="visually-hidden" for="order-{{ $destination->id }}">
                            Position for {{ $destination->name }}
                        </label>
                        <input type="number" class="form-control form-control-sm" style="width:5.5rem"
                               id="order-{{ $destination->id }}"
                               name="destination_order[{{ $destination->id }}]"
                               value="{{ old('destination_order.'.$destination->id, $positionOf[$destination->id] ?? null) }}"
                               min="0" max="999" placeholder="Order">
                    </div>
                @empty
                    <p class="text-muted-vd mb-0">
                        No destinations yet.
                        <a href="{{ route('admin.destinations.index') }}">Add one first</a>.
                    </p>
                @endforelse

                @error('destination_ids.*')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
            @endif

            {{-- LOCATION --------------------------------------------------- --}}
            @if ($section === S::Location)
                <x-form.input name="heading" label="Card heading" required :value="$values['heading']" />

                <div class="alert alert-info small" role="note">
                    These are the words on the About page's address card only. They do not move the
                    municipality on the map or change anything the booking and report screens use.
                </div>

                <div class="row">
                    <div class="col-12 col-md-6">
                        <x-form.input name="municipality" label="Municipality" required :value="$values['municipality']" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="province" label="Province" required :value="$values['province']" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="region" label="Region" required :value="$values['region']" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="country" label="Country" required :value="$values['country']" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="postal_code" label="Postal code" required :value="$values['postal_code']" />
                    </div>
                </div>
            @endif

            {{-- RESORT OWNER CARD ------------------------------------------ --}}
            @if ($section === S::OwnerCta)
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="visible" name="visible" value="1"
                           @checked(old('visible', $values['visible']))>
                    <label class="form-check-label" for="visible">Show this card on the About page</label>
                </div>

                <x-form.input name="heading" label="Card heading" required :value="$values['heading']" />

                <label class="form-label" for="body">Description</label>
                <textarea class="form-control @error('body') is-invalid @enderror"
                          id="body" name="body" rows="4" maxlength="1000">{{ old('body', $values['body']) }}</textarea>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror

                <div class="mt-3">
                    <x-form.input name="button_label" label="Button text" required :value="$values['button_label']" />
                </div>

                <div class="alert alert-info small mb-0" role="note">
                    The button always goes to the resort owner application. Its address is part of the
                    system and is not editable here, so this card cannot break registration.
                </div>
            @endif
        </div>

        {{-- ONE PICTURE ----------------------------------------------------- --}}
        @if ($section->hasImage())
            <div class="vd-panel p-4 mb-3">
                <h2 class="h6 mb-3">Picture</h2>

                @if ($url = $imageUrl($values['image_path'] ?? null))
                    <img src="{{ $url }}" alt="" class="img-fluid rounded mb-3" style="max-height:220px">

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                        <label class="form-check-label" for="remove_image">Remove this picture</label>
                    </div>
                @else
                    <p class="text-muted-vd">No picture yet.</p>
                @endif

                <label class="form-label" for="image">Upload a picture</label>
                <input type="file" class="form-control @error('image') is-invalid @enderror"
                       id="image" name="image"
                       accept="{{ collect(config('visitdasol.uploads.image_mimes'))->map(fn ($m) => '.'.$m)->implode(',') }}">
                <div class="form-text">
                    {{ strtoupper(implode(', ', config('visitdasol.uploads.image_mimes'))) }},
                    up to {{ round(config('visitdasol.uploads.image_max_kb') / 1024, 1) }} MB.
                    Uploading replaces the one above.
                </div>
                @error('image')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        @endif

        {{-- GALLERY --------------------------------------------------------- --}}
        @if ($section->hasGallery())
            <div class="vd-panel p-4 mb-3">
                <h2 class="h6 mb-1">Photographs</h2>
                <p class="text-muted-vd small">
                    Untick a picture to take it off the page. The number sets where it sits —
                    lowest first. New uploads go on the end.
                </p>

                <div class="row g-3 mb-3">
                    @forelse ($values['images'] ?? [] as $index => $image)
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="border rounded p-2 h-100">
                                <img src="{{ $imageUrl($image['path']) }}" alt=""
                                     class="img-fluid rounded mb-2" style="aspect-ratio:4/3;object-fit:cover;width:100%">

                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox"
                                           id="keep-{{ $index }}" name="gallery_path[{{ $index }}]"
                                           value="{{ $image['path'] }}" checked>
                                    <label class="form-check-label small" for="keep-{{ $index }}">Keep</label>
                                </div>

                                <label class="visually-hidden" for="caption-{{ $index }}">Caption</label>
                                <input type="text" class="form-control form-control-sm mb-2"
                                       id="caption-{{ $index }}" name="gallery_caption[{{ $index }}]"
                                       value="{{ $image['caption'] }}" maxlength="200" placeholder="Caption">

                                <label class="visually-hidden" for="order-img-{{ $index }}">Position</label>
                                <input type="number" class="form-control form-control-sm"
                                       id="order-img-{{ $index }}" name="gallery_order[{{ $index }}]"
                                       value="{{ $index }}" min="0" max="999" placeholder="Order">
                            </div>
                        </div>
                    @empty
                        <div class="col-12"><p class="text-muted-vd mb-0">No photographs yet.</p></div>
                    @endforelse
                </div>

                <label class="form-label" for="new_images">Add photographs</label>
                <input type="file" class="form-control @error('new_images.*') is-invalid @enderror"
                       id="new_images" name="new_images[]" multiple
                       accept="{{ collect(config('visitdasol.uploads.image_mimes'))->map(fn ($m) => '.'.$m)->implode(',') }}">
                <div class="form-text">
                    {{ strtoupper(implode(', ', config('visitdasol.uploads.image_mimes'))) }},
                    up to {{ round(config('visitdasol.uploads.image_max_kb') / 1024, 1) }} MB each.
                </div>
                @error('new_images.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Save draft
            </button>
            <a class="btn btn-light border btn-lg" href="{{ route('admin.about.index') }}">Cancel</a>
        </div>

        <p class="form-text mt-2 mb-0">
            Saving keeps this private. Publish it from the About Page Management list
            when you are happy with the preview.
        </p>
    </form>
@endsection
