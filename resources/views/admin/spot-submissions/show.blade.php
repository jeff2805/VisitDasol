@extends('layouts.admin')

@section('title', $spot->name)

@section('content')
    <x-page-header :title="$spot->name" :subtitle="'Barangay '.$spot->barangay">
        <x-status-badge :status="$spot->status" />
        <a class="btn btn-light border" href="{{ route('admin.spot-submissions.index') }}">Back to the queue</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">What the barangay wrote</h2>

                @if (filled($spot->main_image))
                    <img class="vd-gallery__main mb-3" src="{{ $spot->main_image_url }}" alt="">
                @else
                    <div class="alert alert-warning" role="status">
                        No photo was sent with this. A spot with no photo is far less likely to be visited.
                    </div>
                @endif

                <p class="mb-0" style="white-space: pre-line;">{{ $spot->description }}</p>
            </section>

            @if ($spot->images->isNotEmpty())
                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">More photos</h2>
                    <div class="row g-2">
                        @foreach ($spot->images as $image)
                            <div class="col-6 col-md-4">
                                <img class="img-fluid rounded" src="{{ $image->url }}" alt="" loading="lazy">
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- The decision. Approving publishes it exactly as written above,
                 so the office is signing its name to this text. --}}
            @can('review', $spot)
                <section class="vd-panel p-4" aria-labelledby="decide-title">
                    <h2 class="h5 mb-1" id="decide-title">Your decision</h2>
                    <p class="text-muted-vd small mb-3">
                        Approving puts this on the public site under the office's name.
                        Sending it back returns it to Barangay {{ $spot->barangay }} with your note.
                    </p>

                    {{-- One form, two destinations. The note is optional on an
                         approval and required on a return, so each button carries
                         its own formaction and the textarea is written once. No
                         script is involved, which matters: the CSP here forbids
                         inline JavaScript, and a mirrored hidden field would be a
                         silent single point of failure for the office's own words. --}}
                    <form method="POST" action="{{ route('admin.spot-submissions.approve', $spot) }}" data-submit-once>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label class="form-label" for="field-review-remarks">
                                Note to the barangay
                                <span class="text-muted-vd fw-normal small">(required if you send it back)</span>
                            </label>
                            <textarea class="form-control @error('review_remarks') is-invalid @enderror"
                                      id="field-review-remarks" name="review_remarks" rows="3"
                                      placeholder="E.g. the pin is on the wrong side of the river, or please add how to get there from the highway.">{{ old('review_remarks') }}</textarea>
                            @error('review_remarks')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check2" aria-hidden="true"></i> Approve and publish
                            </button>

                            <button type="submit" class="btn btn-outline-danger btn-lg"
                                    formaction="{{ route('admin.spot-submissions.reject', $spot) }}">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Send back
                            </button>
                        </div>
                    </form>
                </section>
            @endcan
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Who sent it</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Name</dt>
                    <dd class="col-7">{{ $spot->creator?->full_name ?? '—' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Barangay</dt>
                    <dd class="col-7">{{ $spot->barangay }}</dd>

                    @if ($spot->creator)
                        <dt class="col-5 text-muted-vd fw-normal">Email</dt>
                        <dd class="col-7 text-break">
                            <a href="mailto:{{ $spot->creator->email }}">{{ $spot->creator->email }}</a>
                        </dd>

                        <dt class="col-5 text-muted-vd fw-normal">Contact number</dt>
                        <dd class="col-7"><a href="tel:{{ $spot->creator->phone }}">{{ $spot->creator->phone }}</a></dd>
                    @endif

                    <dt class="col-5 text-muted-vd fw-normal">Sent in</dt>
                    <dd class="col-7 mb-0">{{ $spot->submitted_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Where it is</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Kind of place</dt>
                    <dd class="col-7">{{ $spot->category?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Landmark</dt>
                    <dd class="col-7">{{ $spot->address ?: '—' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Coordinates</dt>
                    <dd class="col-7 mb-0 font-monospace">
                        {{ number_format((float) $spot->latitude, 6) }},
                        {{ number_format((float) $spot->longitude, 6) }}
                    </dd>
                </dl>
            </section>
        </div>
    </div>
@endsection
