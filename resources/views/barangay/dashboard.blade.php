@extends('layouts.barangay')

@section('title', 'Dashboard')

@section('content')
    <x-page-header :title="'Barangay '.$official->barangay"
                   subtitle="The destinations you have sent to the Municipal Tourism Office.">
        <a class="btn btn-primary" href="{{ route('barangay.spots.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a spot
        </a>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-4">
            <x-stat :value="$published" label="On the site" note="Visitors can see these." />
        </div>
        <div class="col-sm-6 col-xl-4">
            <x-stat :value="$waiting" label="With the office" note="Sent in, not yet decided." />
        </div>
        <div class="col-sm-6 col-xl-4">
            <x-stat :value="$sentBack" label="Needs your attention" note="Sent back for correction." />
        </div>
    </div>

    {{-- The only thing on this page waiting on the official rather than on
         somebody else, so it goes first. --}}
    @if ($needsAttention->isNotEmpty())
        <section class="vd-panel p-4 mb-4" aria-labelledby="sent-back-title">
            <h2 class="h5 mb-3" id="sent-back-title">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                Sent back by the office
            </h2>

            <ul class="list-unstyled mb-0">
                @foreach ($needsAttention as $spot)
                    <li class="border-top py-3">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <strong>{{ $spot->name }}</strong>
                            <a class="btn btn-sm btn-accent" href="{{ route('barangay.spots.edit', $spot) }}">
                                Correct and resend
                            </a>
                        </div>
                        <p class="text-muted-vd small mb-0 mt-1">
                            {{ filled($spot->review_remarks) ? $spot->review_remarks : 'No reason was given.' }}
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="vd-panel p-4" aria-labelledby="recent-title">
        <h2 class="h5 mb-3" id="recent-title">Your most recent</h2>

        @if ($recent->isEmpty())
            <p class="text-muted-vd mb-3">
                You have not sent in anything yet. You know Barangay {{ $official->barangay }}
                better than anyone at the municipal hall does &mdash; start with the place you
                would take a visitor first.
            </p>
            <a class="btn btn-primary" href="{{ route('barangay.spots.create') }}">Add the first one</a>
        @else
            <ul class="list-unstyled mb-3">
                @foreach ($recent as $spot)
                    <li class="d-flex flex-wrap justify-content-between gap-2 border-top py-2">
                        <span>
                            <span class="fw-semibold d-block">{{ $spot->name }}</span>
                            <span class="small text-muted-vd">{{ $spot->category?->name ?? '—' }}</span>
                        </span>
                        <x-status-badge :status="$spot->status" />
                    </li>
                @endforeach
            </ul>

            <a class="btn btn-light border" href="{{ route('barangay.spots.index') }}">See all of them</a>
        @endif
    </section>
@endsection
