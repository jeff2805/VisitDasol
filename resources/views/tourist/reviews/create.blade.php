@extends('layouts.public')

@section('title', $review->exists ? 'Edit your review' : 'Write a review')

@section('content')
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <x-page-header :title="$review->exists ? 'Edit your review' : 'Write a review'"
                               :subtitle="$booking->resort?->name" />

                <form method="POST"
                      action="{{ $review->exists ? route('tourist.reviews.update', $booking) : route('tourist.reviews.store', $booking) }}"
                      novalidate data-submit-once>
                    @csrf
                    @if ($review->exists)
                        @method('PUT')
                    @endif

                    <section class="vd-panel p-4 mb-4">
                        <p class="text-muted-vd small mb-4">
                            You stayed {{ $booking->check_in_date->format('M j') }} to
                            {{ $booking->check_out_date->format('M j, Y') }}. Your review is shown publicly
                            with your first name and last initial.
                        </p>

                        <fieldset class="mb-4">
                            <legend class="form-label">How was your stay?</legend>

                            {{-- Radios rather than a widget: they work with the
                                 keyboard, with a screen reader, and with no
                                 JavaScript at all. --}}
                            <div class="vd-rate" role="radiogroup">
                                @for ($value = 5; $value >= 1; $value--)
                                    <label class="vd-rate__choice">
                                        <input type="radio" name="rating" value="{{ $value }}"
                                               @checked((int) old('rating', $review->rating) === $value) required>
                                        <span class="vd-rate__stars" aria-hidden="true">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <i class="bi bi-star{{ $i <= $value ? '-fill' : '' }}"></i>
                                            @endfor
                                        </span>
                                        <span class="vd-rate__word">
                                            {{ [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'][$value] }}
                                        </span>
                                    </label>
                                @endfor
                            </div>

                            @error('rating')
                                <p class="text-danger small mt-2 mb-0">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <div class="mb-0">
                            <label class="form-label" for="field-comment">
                                Tell other visitors about it
                                <span class="text-muted-vd fw-normal small">(optional)</span>
                            </label>
                            <textarea class="form-control @error('comment') is-invalid @enderror"
                                      id="field-comment" name="comment" rows="5"
                                      placeholder="What was good, what could be better, and what should other guests know?">{{ old('comment', $review->comment) }}</textarea>
                            @error('comment')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Please keep it about your own stay. The tourism office can take down a
                                review that is abusive or has nothing to do with the resort.
                            </div>
                        </div>
                    </section>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-accent btn-lg">
                            {{ $review->exists ? 'Save changes' : 'Publish my review' }}
                        </button>
                        <a class="btn btn-outline-secondary btn-lg"
                           href="{{ route('tourist.bookings.show', $booking) }}">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
