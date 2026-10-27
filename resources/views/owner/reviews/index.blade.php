@extends('layouts.owner')

@section('title', 'Guest reviews')

@section('content')
    <x-page-header title="Guest reviews" :subtitle="$resort->name" />

    <div class="vd-panel p-4 mb-4 d-flex flex-wrap align-items-center gap-3">
        <x-stars :rating="$resort->rating_average" :count="$resort->rating_count" class="fs-5" />
        <p class="text-muted-vd small mb-0 ms-auto">
            Reviews are written by guests whose stay you marked completed. You can reply to
            one, but only the tourism office can take a review down.
        </p>
    </div>

    @if ($reviews->isEmpty())
        <x-empty-state icon="chat-quote" title="No reviews yet"
                       message="Once a guest has finished a stay and you have marked it completed, they can review it." />
    @else
        <ul class="list-unstyled mb-4">
            @foreach ($reviews as $review)
                <li class="vd-panel p-4 mb-3">
                    <div class="vd-review__head mb-2">
                        <span class="vd-review__who">{{ $review->authorName() }}</span>
                        <x-stars :rating="$review->rating" :show-number="false" />
                        <time class="vd-review__when" datetime="{{ $review->created_at->toDateString() }}">
                            {{ $review->created_at->format('M j, Y') }}
                        </time>
                    </div>

                    @if (filled($review->comment))
                        <p class="mb-3">{{ $review->comment }}</p>
                    @endif

                    @if ($review->hasOwnerReply())
                        <div class="vd-review__reply">
                            <p class="vd-review__reply-who">
                                <i class="bi bi-reply-fill" aria-hidden="true"></i> Your reply
                                <span class="text-muted-vd fw-normal">· {{ $review->owner_replied_at?->format('M j, Y') }}</span>
                            </p>
                            <p class="mb-0">{{ $review->owner_reply }}</p>
                        </div>
                    @else
                        <form method="POST" action="{{ route('owner.reviews.reply', $review) }}" data-submit-once>
                            @csrf
                            <label class="form-label" for="reply-{{ $review->id }}">Reply in public</label>
                            <textarea class="form-control mb-2 @error('owner_reply') is-invalid @enderror"
                                      id="reply-{{ $review->id }}" name="owner_reply" rows="3"
                                      placeholder="Thank them, or answer what they raised.">{{ old('owner_reply') }}</textarea>
                            @error('owner_reply')
                                <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                            @enderror
                            <button type="submit" class="btn btn-sm btn-outline-primary">Post reply</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        {{ $reviews->links() }}
    @endif
@endsection
