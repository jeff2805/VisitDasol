{{--
    Whether this applicant appears on the office's accreditation list.

    THE WORDING MATTERS MORE THAN THE BADGE. A green tick beside a resort
    name reads as "approved" to anyone moving quickly, and the office
    moves quickly through a queue. So the line under every outcome says
    what the finding is and what it is not, and the strongest result
    still ends by pointing at the documents.

    Expects the resort with accreditedResort loaded.
--}}
@props(['resort'])

@php
    $status = $resort->accreditation_match_status;
    $matched = $resort->accreditedResort;
@endphp

<section class="vd-panel p-4 mb-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
        <h2 class="h5 mb-0">MTO Accreditation List status</h2>

        @if ($resort->accreditation_checked_at)
            <span class="small text-muted-vd">
                Checked {{ $resort->accreditation_checked_at->diffForHumans() }}
            </span>
        @endif
    </div>

    @if ($status === null)
        {{-- Never checked. Says so rather than showing "not listed",
             which would be a finding nobody made. --}}
        <p class="mb-0 text-muted-vd">
            <i class="bi bi-dash-circle me-1" aria-hidden="true"></i>
            This application has not been checked against the accreditation list.
            @can('viewAny', \App\Models\AccreditedResort::class)
                Upload the list on the
                <a href="{{ route('admin.accredited-resorts.index') }}">Accredited resorts</a>
                screen and it will be checked automatically.
            @endcan
        </p>
    @else
        <p class="mb-2">
            <span class="badge {{ $status->badgeClass() }} fs-6">
                <i class="bi bi-{{ $status->icon() }} me-1" aria-hidden="true"></i>
                {{ strtoupper($status->label()) }}
            </span>
        </p>

        @if (filled($resort->accreditation_match_note))
            {{-- Why the matcher decided this. Without it, "needs review"
                 is an instruction with no reason attached and the
                 reviewer has to guess what to compare. --}}
            <p class="mb-2">{{ ucfirst($resort->accreditation_match_note) }}.</p>
        @endif

        <p class="text-muted-vd small mb-0">{{ $status->explanation() }}</p>

        @if ($matched !== null || filled($resort->matched_accreditation_no))
            <dl class="row small mt-3 mb-0">
                @if (filled($resort->matched_accreditation_no))
                    <dt class="col-sm-4">Accreditation no.</dt>
                    <dd class="col-sm-8 mb-1">{{ $resort->matched_accreditation_no }}</dd>
                @endif

                @if ($matched !== null)
                    <dt class="col-sm-4">On the list as</dt>
                    <dd class="col-sm-8 mb-1">
                        {{ $matched->resort_name }}
                        @if (filled($matched->barangay)) · Brgy. {{ $matched->barangay }} @endif
                    </dd>

                    @if (filled($matched->owner_name))
                        <dt class="col-sm-4">Owner on the list</dt>
                        <dd class="col-sm-8 mb-1">{{ $matched->owner_name }}</dd>
                    @endif

                    <dt class="col-sm-4">Accreditation</dt>
                    <dd class="col-sm-8 mb-1">
                        <span class="badge {{ $matched->accreditation_status->badgeClass() }}">
                            {{ $matched->accreditation_status->label() }}
                        </span>
                        @if ($matched->valid_until)
                            <span class="ms-1">
                                valid until {{ $matched->valid_until->format('j M Y') }}
                            </span>
                        @endif
                    </dd>
                @else
                    {{-- The row was matched once and has since been
                         replaced by a later upload. The number above is
                         the copy kept on the application for exactly
                         this case. --}}
                    <dt class="col-sm-4">On the list as</dt>
                    <dd class="col-sm-8 mb-1 text-muted-vd">
                        The matching record is no longer on the current list.
                    </dd>
                @endif
            </dl>
        @endif
    @endif
</section>
