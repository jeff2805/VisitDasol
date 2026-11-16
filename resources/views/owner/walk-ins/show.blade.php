@extends('layouts.owner')

@section('title', 'Walk-in '.$record->booking_reference)

@section('content')
    <x-page-header :title="$record->booking_reference"
                   :subtitle="'Walk-in record · '.$record->resort?->name">
        <a class="btn btn-light border" href="{{ route('owner.walk-ins.index') }}">Back to records</a>

        @can('updateWalkIn', $record)
            <a class="btn btn-light border" href="{{ route('owner.walk-ins.edit', $record) }}">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
            </a>
        @endcan
    </x-page-header>

    @error('submission')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            {{-- Where the record stands with the office, and what can be done
                 about it, together at the top. Nothing else on the page is
                 actionable. --}}
            <section class="vd-panel p-4 mb-3" aria-labelledby="office-title">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <h2 class="h5 mb-0" id="office-title">Municipal Tourism Office</h2>
                    <x-status-badge :status="$record->submission_status" />
                </div>

                <p class="text-muted-vd">{{ $record->submission_status->description() }}</p>

                @if (filled($record->return_remarks))
                    <div class="alert alert-warning mb-3" role="alert">
                        <strong class="d-block">What they asked you to correct</strong>
                        {{ $record->return_remarks }}
                    </div>
                @endif

                {{-- The gap is shown while the record is still the resort's,
                     so it is found before the office ever sees it. --}}
                @if ($missing && $record->submission_status->isSubmittable())
                    <div class="alert alert-info" role="alert">
                        <strong class="d-block">Not ready to send</strong>
                        This record cannot be sent yet: {{ $missing }}
                        <a href="{{ route('owner.walk-ins.edit', $record) }}">Fill it in</a>.
                    </div>
                @endif

                @can('submitWalkIn', $record)
                    @unless ($missing)
                        <x-confirm-form
                            id="submit-walk-in"
                            :action="route('owner.walk-ins.submit', $record)"
                            method="POST"
                            title="Send this record to the tourism office?"
                            message="They will review the visitor details. You will not be able to change the record while they have it, and you will be told if they need anything corrected."
                            :trigger="$record->submission_status === \App\Enums\WalkInSubmissionStatus::Returned ? 'Resubmit to MTO' : 'Submit to MTO'"
                            confirm="Yes, send it"
                            variant="primary"
                            icon="send" />
                    @endunless
                @endcan

                @if ($record->submission_status === \App\Enums\WalkInSubmissionStatus::Accepted)
                    <p class="mb-0">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                        Accepted on {{ $record->accepted_at?->format('M j, Y g:i A') }}.
                        This stay now counts towards the municipality's tourism report.
                    </p>
                @endif
            </section>

            <section class="vd-panel p-4 mb-3">
                <h2 class="h5 mb-3">The guest</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Name</dt>
                    <dd class="col-sm-8">{{ $record->contact_name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-sm-8">{{ $record->contact_phone }}</dd>

                    @if (filled($record->contact_email))
                        <dt class="col-sm-4 text-muted-vd fw-normal">Email</dt>
                        <dd class="col-sm-8 text-break">{{ $record->contact_email }}</dd>
                    @endif

                    @if (filled($record->guest_address))
                        <dt class="col-sm-4 text-muted-vd fw-normal">Address</dt>
                        <dd class="col-sm-8">{{ $record->guest_address }}</dd>
                    @endif

                    <dt class="col-sm-4 text-muted-vd fw-normal">Travelling from</dt>
                    <dd class="col-sm-8">
                        {{ $record->origin?->label() ?? 'Not recorded' }}
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Party</dt>
                    <dd class="col-sm-8"><x-guest-party :booking="$record" /></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Male / female</dt>
                    <dd class="col-sm-8 mb-0">
                        @if ($record->guests_male === null && $record->guests_female === null)
                            Not recorded
                        @else
                            {{ $record->guests_male ?? 0 }} male, {{ $record->guests_female ?? 0 }} female
                        @endif
                    </dd>
                </dl>

                @if (filled($record->special_requests))
                    <div class="border-top mt-3 pt-3">
                        <strong class="d-block">What they asked for</strong>
                        <span class="text-muted-vd">{{ $record->special_requests }}</span>
                    </div>
                @endif
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">The stay</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Room</dt>
                    <dd class="col-sm-8">{{ $record->rooms_booked }} &times; {{ $record->room?->name }}</dd>

                    <x-stay-dates :booking="$record" />

                    <dt class="col-sm-4 text-muted-vd fw-normal">Recorded on</dt>
                    <dd class="col-sm-8 mb-0">{{ $record->created_at->format('M j, Y g:i A') }}</dd>
                </dl>
            </section>
        </div>

        <div class="col-12 col-lg-5">
            {{-- The resort's own figures. The tourism office never sees this
                 panel: its screens select no money column at all. --}}
            <section class="vd-panel p-4" aria-labelledby="charge-title">
                <h2 class="h5 mb-3" id="charge-title">What the stay comes to</h2>
                <x-price-breakdown :booking="$record" />
            </section>

            {{-- The record's whole journey, so a figure questioned months
                 later can be accounted for. --}}
            <section class="vd-panel p-4 mt-3" aria-labelledby="history-title">
                <h2 class="h5 mb-3" id="history-title">Submission history</h2>

                @if ($record->submissions->isEmpty())
                    <p class="text-muted-vd mb-0">
                        Not sent to the tourism office yet.
                    </p>
                @else
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
                @endif
            </section>
        </div>
    </div>
@endsection
