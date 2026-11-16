@extends('layouts.admin')

@section('title', 'Walk-in '.$record->booking_reference)

@section('content')
    <x-page-header :title="$record->booking_reference"
                   :subtitle="'Walk-in record from '.$record->resort?->name">
        <a class="btn btn-light border" href="{{ route('admin.walk-ins.index') }}">Back to submissions</a>
    </x-page-header>

    @error('review')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    @error('return_remarks')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <section class="vd-panel p-4 mb-3" aria-labelledby="visitor-title">
                <h2 class="h5 mb-3" id="visitor-title">Visitor information</h2>

                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Tourist</dt>
                    <dd class="col-sm-8">{{ $record->contact_name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-sm-8">{{ $record->contact_phone }}</dd>

                    @if (filled($record->guest_address))
                        <dt class="col-sm-4 text-muted-vd fw-normal">Address</dt>
                        <dd class="col-sm-8">{{ $record->guest_address }}</dd>
                    @endif

                    <dt class="col-sm-4 text-muted-vd fw-normal">Travelling from</dt>
                    <dd class="col-sm-8">
                        @if ($record->origin)
                            {{ $record->origin->label() }}
                        @else
                            <span class="text-danger">Not recorded</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Party</dt>
                    <dd class="col-sm-8"><x-guest-party :booking="$record" /></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Male / female</dt>
                    <dd class="col-sm-8 mb-0">
                        @if ($record->guests_male === null && $record->guests_female === null)
                            <span class="text-danger">Not recorded</span>
                        @else
                            {{ $record->guests_male ?? 0 }} male, {{ $record->guests_female ?? 0 }} female
                            @if (($record->guests_male ?? 0) + ($record->guests_female ?? 0) !== $record->guests)
                                <span class="d-block small text-danger">
                                    These do not add up to {{ $record->guests }} guests.
                                </span>
                            @endif
                        @endif
                    </dd>
                </dl>

                @if (filled($record->special_requests))
                    <div class="border-top mt-3 pt-3">
                        <strong class="d-block">What the guest asked for</strong>
                        <span class="text-muted-vd">{{ $record->special_requests }}</span>
                    </div>
                @endif
            </section>

            <section class="vd-panel p-4 mb-3">
                <h2 class="h5 mb-3">Accommodation</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Resort</dt>
                    <dd class="col-sm-8">
                        {{ $record->resort?->name }}
                        <span class="d-block small text-muted-vd">{{ $record->resort?->barangay }}</span>
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Room</dt>
                    <dd class="col-sm-8">{{ $record->rooms_booked }} &times; {{ $record->room?->name }}</dd>

                    <x-stay-dates :booking="$record" />
                </dl>
            </section>

            {{-- The figures this record would add to the monthly return,
                 worked out the same way ReportService works them out, so a
                 reviewer can see what they are accepting. --}}
            <section class="vd-panel p-4" aria-labelledby="return-title">
                <h2 class="h5 mb-1" id="return-title">What this adds to the return</h2>
                <p class="text-muted-vd small">
                    Counted only once this record is accepted.
                </p>

                <dl class="row mb-0">
                    <dt class="col-7 text-muted-vd fw-normal">Check-ins</dt>
                    <dd class="col-5 text-end">1</dd>

                    <dt class="col-7 text-muted-vd fw-normal">Guest nights</dt>
                    <dd class="col-5 text-end">{{ $record->guests * $record->nights }}</dd>

                    <dt class="col-7 text-muted-vd fw-normal">Day tour</dt>
                    <dd class="col-5 text-end">{{ $record->booking_type->isTour() ? $record->guests : 0 }}</dd>

                    <dt class="col-7 text-muted-vd fw-normal">Room use</dt>
                    <dd class="col-5 text-end">{{ $record->rooms_booked * max(1, $record->nights) }}</dd>

                    <dt class="col-7 text-muted-vd fw-normal">Male / female</dt>
                    <dd class="col-5 text-end">
                        {{ $record->guests_male ?? 0 }} / {{ $record->guests_female ?? 0 }}
                    </dd>

                    <dt class="col-7 text-muted-vd fw-normal mb-0">
                        {{ $record->origin?->shortLabel() ?? 'Origin not stated' }}
                    </dt>
                    <dd class="col-5 text-end mb-0">{{ $record->origin ? $record->guests : 0 }}</dd>
                </dl>
            </section>
        </div>

        <div class="col-12 col-lg-5">
            <section class="vd-panel p-4 mb-3" aria-labelledby="decide-title">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <h2 class="h5 mb-0" id="decide-title">Review</h2>
                    <x-status-badge :status="$record->submission_status" />
                </div>

                <p class="text-muted-vd">
                    Submitted {{ $record->submitted_at?->format('M j, Y g:i A') }}.
                </p>

                @can('reviewWalkIn', $record)
                    <div class="d-flex flex-wrap gap-2">
                        <x-confirm-form
                            id="accept-walk-in"
                            :action="route('admin.walk-ins.accept', $record)"
                            method="PATCH"
                            title="Accept this walk-in record?"
                            message="It will be counted in the municipality's tourism report and the resort will no longer be able to change it."
                            trigger="Accept"
                            confirm="Yes, accept"
                            variant="primary"
                            icon="check2" />

                        <x-confirm-form
                            id="return-walk-in"
                            :action="route('admin.walk-ins.return', $record)"
                            method="PATCH"
                            title="Return this record for correction?"
                            message="The resort will be told what to change. The guest's room stays booked."
                            trigger="Return for correction"
                            confirm="Return it"
                            variant="warning"
                            icon="arrow-counterclockwise">

                            <div class="mt-3">
                                <label class="form-label" for="return_remarks">
                                    What needs correcting
                                </label>
                                <textarea class="form-control" id="return_remarks" name="return_remarks"
                                          rows="4" required minlength="10" maxlength="1000"
                                          placeholder="Say what is wrong, so the resort knows what to change."></textarea>
                                <div class="form-text">
                                    The resort reads this, and it is kept in the record's history.
                                </div>
                            </div>
                        </x-confirm-form>
                    </div>
                @else
                    <p class="mb-0">
                        @if ($record->submission_status === \App\Enums\WalkInSubmissionStatus::Accepted)
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                            Accepted on {{ $record->accepted_at?->format('M j, Y g:i A') }}
                            @if ($record->reviewedBy)
                                by {{ $record->reviewedBy->full_name }}
                            @endif.
                        @else
                            Waiting on the resort to correct and resubmit it.
                        @endif
                    </p>
                @endcan

                @if (filled($record->return_remarks))
                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                        <strong class="d-block">Last returned with</strong>
                        {{ $record->return_remarks }}
                    </div>
                @endif
            </section>

            <section class="vd-panel p-4" aria-labelledby="history-title">
                <h2 class="h5 mb-3" id="history-title">Submission history</h2>

                <ol class="list-unstyled mb-0">
                    @foreach ($record->submissions as $event)
                        <li class="border-top py-2">
                            <span class="d-block fw-semibold">
                                {{ \Illuminate\Support\Str::headline($event->action) }}
                            </span>
                            <span class="d-block small text-muted-vd">
                                {{ $event->created_at->format('M j, Y g:i A') }}
                                @if ($event->actor)
                                    &middot; {{ $event->actor->full_name }}
                                @endif
                            </span>
                            @if (filled($event->remarks))
                                <span class="d-block small mt-1">{{ $event->remarks }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
@endsection
