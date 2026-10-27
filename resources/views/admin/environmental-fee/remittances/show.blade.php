@extends('layouts.admin')

@section('title', $remittance->reference)

@section('content')
    <x-page-header :title="$remittance->reference"
                   :subtitle="$remittance->checkpoint?->name.' · '.$remittance->collector?->first_name.' '.$remittance->collector?->last_name">
        <a class="btn btn-light border"
           href="{{ route('admin.environmental-fee.remittances.index') }}">Back to handovers</a>
        <button type="button" class="btn btn-light border" data-print hidden>
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
        </button>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-remittance-slip :remittance="$remittance" :receipts="$receipts" :live="$live" />
        </div>

        <div class="col-lg-5">
            @if ($remittance->status->isReviewed())
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-2">Already counted</h2>
                    <p class="text-muted-vd mb-0">
                        {{ $remittance->reviewedBy?->first_name }} {{ $remittance->reviewedBy?->last_name }}
                        counted this on {{ $remittance->reviewed_at?->format('M j, Y g:i A') }}.
                        A count is recorded once and is not edited afterwards &mdash; if
                        something was wrong with it, that belongs in the audit trail as a
                        new entry, not as a quiet correction of this one.
                    </p>
                </section>
            @elseif (! $mayReview)
                {{-- The separation that makes the whole thing worth
                     doing. Spelled out rather than hiding the form,
                     so the officer knows why it is not there. --}}
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-2">You cannot count this one</h2>
                    <p class="text-muted-vd mb-0">
                        You handed this in yourself. Somebody else from the office has to
                        count it &mdash; a person who could both turn money in and certify
                        their own envelope would make the check meaningless.
                    </p>
                </section>
            @else
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">Count it</h2>
                    <p class="text-muted-vd small mb-3">
                        Count the cash in front of you and enter the real figure. Whether
                        it agrees is worked out from the receipts, not chosen here.
                    </p>

                    <form method="POST"
                          action="{{ route('admin.environmental-fee.remittances.review', $remittance) }}"
                          data-submit-once>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label class="form-label" for="field-counted-amount">
                                Amount counted <span class="text-danger">*</span>
                            </label>
                            <div class="input-group has-validation">
                                <span class="input-group-text">₱</span>
                                <input type="text" inputmode="decimal"
                                       class="form-control @error('counted_amount') is-invalid @enderror"
                                       id="field-counted-amount" name="counted_amount"
                                       value="{{ old('counted_amount') }}" required>
                                @error('counted_amount')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">
                                The receipts say @peso($remittance->expected_amount, true).
                                The collector declared @peso($remittance->declared_amount, true).
                                Deliberately not pre-filled &mdash; a figure already in the box
                                is a figure that gets agreed to without counting.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="field-review-note">
                                Note
                                <span class="text-muted-vd fw-normal small">
                                    (required if it does not match)
                                </span>
                            </label>
                            <textarea class="form-control @error('review_note') is-invalid @enderror"
                                      id="field-review-note" name="review_note" rows="3"
                                      placeholder="If the count differs from the receipts, say what you found and what happens next.">{{ old('review_note') }}</textarea>
                            @error('review_note')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Record the count
                        </button>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
