@extends('layouts.admin')

@section('title', 'Edit destination')

@php($limit = config('visitdasol.uploads.gallery_limits.destination'))

@section('content')
    <x-page-header title="Edit destination" :subtitle="$destination->name">
        @if ($destination->isActive())
            <x-external-link class="btn btn-outline-primary" :href="route('destinations.show', $destination)">
                View public page
            </x-external-link>
        @endif
        <a class="btn btn-light border" href="{{ route('admin.destinations.index') }}">Back to list</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.destinations.update', $destination) }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('admin.destinations._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('admin.destinations.index') }}">Cancel</a>
        </div>
    </form>

    <section class="vd-panel p-4 mt-4">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0">Photos</h2>
            <span class="text-muted-vd small">{{ $destination->images->count() }} of {{ $limit }}</span>
        </div>

        @error('images')
            <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
        @enderror

        @if ($destination->images->isNotEmpty())
            <div class="row g-3 mb-3">
                @foreach ($destination->images as $image)
                    <div class="col-6 col-md-3">
                        <figure class="mb-0">
                            <img class="w-100 rounded" style="aspect-ratio: 4/3; object-fit: cover" src="{{ $image->url }}" alt="{{ $image->caption }}">
                            <figcaption class="d-flex gap-1 mt-1">
                                @if ($destination->main_image === $image->path)
                                    <span class="vd-badge vd-badge--active">Cover</span>
                                @else
                                    <form method="POST" action="{{ route('admin.destinations.images.cover', [$destination, $image]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-light border">Use as cover</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.destinations.images.destroy', [$destination, $image]) }}"
                                      data-confirm="Remove this photo?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-light border text-danger" aria-label="Remove photo">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </figcaption>
                        </figure>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-muted-vd">No photos yet.</p>
        @endif

        @if ($destination->images->count() < $limit)
            <form method="POST" action="{{ route('admin.destinations.images.store', $destination) }}" enctype="multipart/form-data" data-submit-once>
                @csrf
                <label class="form-label" for="field-images">Add photos</label>
                <div class="input-group">
                    <input type="file" class="form-control" id="field-images" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" required>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
                <div class="form-text">JPG, PNG, or WEBP up to 3 MB each.</div>
            </form>
        @endif
    </section>
@endsection
