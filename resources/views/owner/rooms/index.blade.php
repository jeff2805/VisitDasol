@extends('layouts.owner')

@section('title', 'Rooms')

@php($photoLimit = config('visitdasol.uploads.gallery_limits.room'))

@section('content')
    <x-page-header title="Room types" :subtitle="$resort->name.' Â· guests book a room type, not a specific room.'">
        <a class="btn btn-primary" href="{{ route('owner.rooms.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add room type
        </a>
    </x-page-header>

    @error('images')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    @if ($rooms->isEmpty())
        <x-empty-state icon="door-open" title="No room types yet"
                       message="Add a room type with its rate, how many guests it takes, and how many rooms of that kind you have. Guests can only book once a room type exists.">
            <a class="btn btn-primary" href="{{ route('owner.rooms.create') }}">Add your first room type</a>
        </x-empty-state>
    @else
        <div class="row g-4">
            @foreach ($rooms as $room)
                <div class="col-xl-6">
                    <article class="vd-panel p-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h2 class="h5 mb-1">{{ $room->name }}</h2>
                                <p class="text-muted-vd small mb-0">
                                    {{ $room->room_type->label() }} Â·
                                    up to {{ $room->capacity }} {{ Str::plural('guest', $room->capacity) }} Â·
                                    {{ $room->total_rooms }} {{ Str::plural('unit', $room->total_rooms) }}
                                    &middot;
                                    {{ $room->bed_count }} {{ Str::plural('bed', $room->bed_count) }}
                                </p>

                                {{-- The policy a guest is shown, on the screen where
                                     an owner would go looking to change it. --}}
                                @if ($room->hasChildDiscount() || $room->hasPwdDiscount())
                                    <p class="small mb-0 mt-1">
                                        @if ($room->hasChildDiscount())
                                            <span class="badge text-bg-light border me-1">
                                                {{ $room->child_discount_percent }}% children {{ $room->childAgeRange() }}
                                            </span>
                                        @endif
                                        @if ($room->hasPwdDiscount())
                                            <span class="badge text-bg-light border">
                                                {{ $room->pwd_discount_percent }}% PWD
                                            </span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                            <div class="text-end">
                                <strong class="vd-card__price d-block">@peso($room->price_per_night)</strong>
                                <span class="text-muted-vd small">per night</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <x-status-badge :status="$room->status" />
                            @if ($committed[$room->id] > 0)
                                <span class="small text-muted-vd">
                                    {{ $committed[$room->id] }} of {{ $room->total_rooms }} held on the busiest upcoming night
                                </span>
                            @elseif ($room->bookings_count > 0)
                                <span class="small text-muted-vd">{{ $room->bookings_count }} past {{ Str::plural('reservation', $room->bookings_count) }}</span>
                            @endif
                        </div>

                        @if (filled($room->description))
                            <p class="text-muted-vd small mt-3 mb-0">{{ $room->description }}</p>
                        @endif

                        {{-- Photos --}}
                        <div class="mt-3">
                            @if ($room->images->isNotEmpty())
                                <div class="row g-2 mb-2">
                                    @foreach ($room->images as $image)
                                        <div class="col-4 col-sm-3">
                                            <figure class="mb-0">
                                                <img class="w-100 rounded" style="aspect-ratio: 4/3; object-fit: cover"
                                                     src="{{ $image->url }}" alt="{{ $image->caption }}">
                                                <figcaption>
                                                    <form method="POST" action="{{ route('owner.rooms.images.destroy', [$room, $image]) }}"
                                                          data-confirm="Remove this photo?">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-light border text-danger w-100 mt-1"
                                                                aria-label="Remove photo">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                </figcaption>
                                            </figure>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($room->images->count() < $photoLimit)
                                <form method="POST" action="{{ route('owner.rooms.images.store', $room) }}"
                                      enctype="multipart/form-data" data-submit-once>
                                    @csrf
                                    <label class="form-label small" for="images-{{ $room->id }}">
                                        Photos ({{ $room->images->count() }} of {{ $photoLimit }})
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input type="file" class="form-control" id="images-{{ $room->id }}"
                                               name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" required>
                                        <button type="submit" class="btn btn-outline-primary">Upload</button>
                                    </div>
                                </form>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3 border-top pt-3">
                            <a class="btn btn-sm btn-primary" href="{{ route('owner.rooms.edit', $room) }}">Edit</a>

                            <form method="POST" action="{{ route('owner.rooms.status', $room) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-light border">
                                    {{ $room->isActive() ? 'Stop bookings' : 'Allow bookings' }}
                                </button>
                            </form>

                            {{-- Display only: the delete route still checks RoomPolicy. --}}
                            @if ($room->bookings_count === 0)
                                <form method="POST" action="{{ route('owner.rooms.destroy', $room) }}"
                                      data-confirm="Delete {{ $room->name }}? This cannot be undone.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-light border text-danger">Delete</button>
                                </form>
                            @else
                                <span class="btn btn-sm btn-light border disabled" aria-disabled="true"
                                      title="Room types with reservations cannot be deleted">Delete</span>
                            @endif
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
