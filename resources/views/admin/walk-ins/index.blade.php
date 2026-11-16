@extends('layouts.admin')

@section('title', 'Submitted walk-in records')

@section('content')
    @php
        $waiting = ($counts[\App\Enums\WalkInSubmissionStatus::PendingReview->value] ?? 0)
            + ($counts[\App\Enums\WalkInSubmissionStatus::Resubmitted->value] ?? 0);
    @endphp

    <x-page-header title="Submitted walk-in records"
                   subtitle="Guests the resorts took at their own gates. Visitor information only — no resort income." />

    @error('review')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    @if ($waiting > 0)
        <div class="alert alert-info d-flex gap-2" role="alert">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <span>
                {{ $waiting }} {{ \Illuminate\Support\Str::plural('record', $waiting) }}
                waiting for review, oldest first.
            </span>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.walk-ins.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="wi-q">Resort, guest or reference</label>
                <input type="search" class="form-control" id="wi-q" name="q" value="{{ $filters['q'] }}">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="wi-resort">Resort</label>
                <select class="form-select" id="wi-resort" name="resort">
                    <option value="">All resorts</option>
                    @foreach ($resorts as $id => $name)
                        <option value="{{ $id }}" @selected($filters['resort']?->id === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="wi-status">Status</label>
                <select class="form-select" id="wi-status" name="status">
                    <option value="">Any status</option>
                    @foreach (\App\Enums\WalkInSubmissionStatus::cases() as $case)
                        @continue($case === \App\Enums\WalkInSubmissionStatus::Draft)
                        <option value="{{ $case->value }}" @selected($filters['status'] === $case)>
                            {{ $case->label() }}@if (($counts[$case->value] ?? 0) > 0) ({{ $counts[$case->value] }})@endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="wi-sub-from">Submitted from</label>
                <input type="date" class="form-control" id="wi-sub-from" name="submitted_from"
                       value="{{ $filters['submitted_from'] }}">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="wi-from">Check-in from</label>
                <input type="date" class="form-control" id="wi-from" name="from" value="{{ $filters['from'] }}">
            </div>

            <div class="col-6 col-md-1 d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </form>

    @if ($records->isEmpty())
        <x-empty-state icon="inbox" title="Nothing submitted yet"
                       message="Walk-in records appear here once a resort sends them. A resort's own drafts are not shown." />
    @else
        <x-data-table label="Submitted walk-in records">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Resort</th>
                    <th scope="col">Tourist</th>
                    <th scope="col">Stay</th>
                    <th scope="col" class="text-end">Guests</th>
                    <th scope="col">Submitted</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach ($records as $record)
                <tr>
                    <td class="font-monospace">{{ $record->booking_reference }}</td>
                    <td>
                        <span class="d-block">{{ $record->resort?->name }}</span>
                        <span class="small text-muted-vd">{{ $record->resort?->barangay }}</span>
                    </td>
                    <td>{{ $record->contact_name }}</td>
                    <td>
                        @if ($record->booking_type->isOvernight())
                            {{ $record->check_in_date->format('M j') }} &ndash;
                            {{ $record->check_out_date->format('M j, Y') }}
                        @else
                            {{ $record->check_in_date->format('M j, Y') }}
                            <span class="d-block small text-muted-vd">{{ $record->booking_type->label() }}</span>
                        @endif
                    </td>
                    <td class="text-end">{{ $record->guests }}</td>
                    <td>{{ $record->submitted_at?->format('M j, Y') }}</td>
                    <td><x-status-badge :status="$record->submission_status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light border" href="{{ route('admin.walk-ins.show', $record) }}">
                            Review
                        </a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $records->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
