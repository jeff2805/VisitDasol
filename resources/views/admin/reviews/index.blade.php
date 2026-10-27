@extends('layouts.admin')

@section('title', 'Reviews')

@section('content')
    <x-page-header title="Guest reviews"
                   subtitle="Reviews go up as they are written. Take one down only when it is abusive, off-topic, or not about the stay." />

    <form method="GET" class="vd-panel p-3 mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-sm-5">
                <label class="form-label" for="q">Search the text</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $search }}"
                       placeholder="A word in the review">
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All ({{ array_sum($counts) }})</option>
                    <option value="published" @selected($status?->value === 'published')>
                        Published ({{ $counts['published'] }})
                    </option>
                    <option value="hidden" @selected($status?->value === 'hidden')>
                        Hidden ({{ $counts['hidden'] }})
                    </option>
                </select>
            </div>
            <div class="col-sm-3 d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </form>

    @if ($reviews->isEmpty())
        <x-empty-state icon="chat-quote" title="No reviews found"
                       message="Nothing matches those filters yet." />
    @else
        <ul class="list-unstyled mb-4">
            @foreach ($reviews as $review)
                <li class="vd-panel p-4 mb-3">
                    <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                        <div class="vd-review__head mb-0">
                            <span class="vd-review__who">{{ $review->authorName() }}</span>
                            <x-stars :rating="$review->rating" :show-number="false" />
                            <time class="vd-review__when">{{ $review->created_at->format('M j, Y') }}</time>
                        </div>

                        <span class="ms-auto">
                            <x-status-badge :status="$review->status" />
                        </span>
                    </div>

                    <p class="small text-muted-vd mb-2">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        {{ $review->resort?->name ?? 'Resort removed' }}
                    </p>

                    @if (filled($review->comment))
                        <p class="mb-3">{{ $review->comment }}</p>
                    @else
                        <p class="text-muted-vd fst-italic mb-3">A rating with no comment.</p>
                    @endif

                    @if ($review->status === \App\Enums\ReviewStatus::Hidden)
                        <div class="alert alert-secondary py-2 small mb-3" role="status">
                            <strong>Hidden</strong>
                            @if ($review->hiddenBy) by {{ $review->hiddenBy->full_name }} @endif
                            @if ($review->hidden_at) on {{ $review->hidden_at->format('M j, Y') }} @endif
                            @if ($review->hidden_reason) — {{ $review->hidden_reason }} @endif
                        </div>

                        <form method="POST" action="{{ route('admin.reviews.restore', $review) }}"
                              data-confirm="Show this review to visitors again?">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm btn-outline-primary">Show it again</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.reviews.hide', $review) }}"
                              class="row g-2 align-items-end" data-submit-once>
                            @csrf
                            @method('PATCH')
                            <div class="col-sm-8">
                                <label class="form-label small" for="reason-{{ $review->id }}">
                                    Reason for taking it down
                                </label>
                                <input type="text" class="form-control form-control-sm"
                                       id="reason-{{ $review->id }}" name="hidden_reason"
                                       placeholder="e.g. abusive language, not about this resort">
                            </div>
                            <div class="col-sm-4 d-grid">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Hide from visitors</button>
                            </div>
                        </form>
                        @error('hidden_reason')
                            <p class="text-danger small mt-2 mb-0">{{ $message }}</p>
                        @enderror
                    @endif
                </li>
            @endforeach
        </ul>

        {{ $reviews->links() }}
    @endif
@endsection
