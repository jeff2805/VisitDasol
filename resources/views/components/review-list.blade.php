{{--
    A list of published reviews, with the resort's replies.

    <x-review-list :reviews="$reviews" />

    Names are shortened to "Bea S." by Review::authorName(), so a guest's full
    name is never published next to their opinion.
--}}
@props(['reviews'])

@if ($reviews->isEmpty())
    <p class="text-muted-vd mb-0">No reviews yet. Guests can write one after their stay.</p>
@else
    <ul class="vd-reviews">
        @foreach ($reviews as $review)
            <li class="vd-review">
                <div class="vd-review__head">
                    <span class="vd-review__who">{{ $review->authorName() }}</span>
                    <x-stars :rating="$review->rating" :show-number="false" />
                    <time class="vd-review__when" datetime="{{ $review->created_at->toDateString() }}">
                        {{ $review->created_at->format('M j, Y') }}
                    </time>
                </div>

                @if (filled($review->comment))
                    <p class="vd-review__text">{{ $review->comment }}</p>
                @endif

                @if ($review->hasOwnerReply())
                    <div class="vd-review__reply">
                        <p class="vd-review__reply-who">
                            <i class="bi bi-reply-fill" aria-hidden="true"></i>
                            Reply from the resort
                            @if ($review->owner_replied_at)
                                <span class="text-muted-vd fw-normal">· {{ $review->owner_replied_at->format('M j, Y') }}</span>
                            @endif
                        </p>
                        <p class="mb-0">{{ $review->owner_reply }}</p>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@endif
