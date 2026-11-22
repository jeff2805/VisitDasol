{{--
    Every photo of one room, at a size a guest can actually judge.

    Opened by the thumbnails in the room's strip, which pass the photo they
    were showing so the gallery starts there rather than always at the first.
    A dialog rather than a page, because choosing a room means comparing it
    against the ones above and below it — going somewhere else to look at the
    bed loses that.

    The strip shows three. This holds all of them: before it existed, a room
    with five photos had two that could not be reached from anywhere.
--}}
@props([
    'id',
    'room',
    'resort' => null,
    'photos',
])

@php
    $carouselId = $id.'-carousel';
    $total = $photos->count();
@endphp

<div class="modal fade" id="{{ $id }}" tabindex="-1"
     aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="{{ $id }}-title">
                    {{ $room->name }}
                    @if ($resort)
                        <span class="text-muted-vd fw-normal">· {{ $resort->name }}</span>
                    @endif
                </h2>

                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Close photos"></button>
            </div>

            <div class="modal-body p-0">
                {{-- ride="false" and interval="false": a guest looking at a
                     bathroom should not have it slide away mid-look. The
                     resort's own header gallery autoplays because nobody
                     asked it to open; this was opened deliberately. --}}
                <div id="{{ $carouselId }}"
                     class="carousel slide vd-photo-carousel"
                     data-bs-ride="false"
                     data-bs-interval="false"
                     data-room-photo-carousel>
                    <div class="carousel-inner">
                        @foreach ($photos as $index => $image)
                            <div class="carousel-item @if ($index === 0) active @endif">
                                {{-- contain, not cover: this is the view where
                                     the whole photo matters, and a cropped
                                     room is what the thumbnail already gave. --}}
                                <div class="vd-photo-carousel__frame">
                                    <img src="{{ $image->url }}"
                                         alt="{{ $image->caption ?? $room->name }}"
                                         loading="lazy">
                                </div>

                                {{-- Always rendered, so the frame does not jump
                                     by a line between a captioned photo and an
                                     uncaptioned one. Carries the position even
                                     when the owner wrote no caption. --}}
                                <p class="vd-photo-carousel__caption">
                                    <span class="text-muted-vd">
                                        Photo {{ $index + 1 }} of {{ $total }}
                                    </span>
                                    @if (filled($image->caption))
                                        <span class="vd-photo-carousel__text">{{ $image->caption }}</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>

                    @if ($total > 1)
                        <button class="carousel-control-prev" type="button"
                                data-bs-target="#{{ $carouselId }}" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Previous photo</span>
                        </button>

                        <button class="carousel-control-next" type="button"
                                data-bs-target="#{{ $carouselId }}" data-bs-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="visually-hidden">Next photo</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
