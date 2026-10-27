@extends('layouts.admin')

@section('title', 'Activity log')

@php
    // Where a log entry's subject can be opened, if it still exists.
    $subjectLink = function ($log) {
        $subject = $log->subject;

        if ($subject === null) {
            return null;
        }

        return match ($log->subject_type) {
            'user' => route('admin.users.show', $subject),
            'resort' => route('admin.resorts.show', $subject),
            'booking' => route('admin.bookings.show', $subject),
            'destination' => route('admin.destinations.edit', $subject),
            'announcement' => route('admin.announcements.edit', $subject),
            default => null,
        };
    };
@endphp

@section('content')
    <x-page-header title="Activity log"
                   subtitle="Who did what, and when. Entries are only ever added, never changed or removed." />

    <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $search }}" placeholder="Name or action">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-group">Part of the system</label>
                <select class="form-select" id="filter-group" name="group">
                    <option value="">Everything</option>
                    @foreach ($groups as $item)
                        <option value="{{ $item }}" @selected($group === $item)>{{ ucfirst($item) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-user">Done by</label>
                <select class="form-select" id="filter-user" name="user">
                    <option value="">Anyone</option>
                    @foreach ($actors as $person)
                        <option value="{{ $person->id }}" @selected($actor?->id === $person->id)>{{ $person->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-from">From</label>
                <input type="date" class="form-control" id="filter-from" name="from" value="{{ $from }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-to">To</label>
                <input type="date" class="form-control" id="filter-to" name="to" value="{{ $to }}">
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a class="btn btn-light border" href="{{ route('admin.activity-logs.index') }}">Clear</a>
        </div>
    </form>

    @if ($logs->isEmpty())
        <x-empty-state icon="clock-history" title="Nothing recorded yet"
                       message="Approvals, status changes, and other important actions are written here as they happen." />
    @else
        {{-- compact: a log is read by scanning down it, so the rows are
             tighter than on a listing people act row by row. --}}
        <x-data-table label="Activity log" :compact="true">
            <x-slot:head>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">What happened</th>
                    <th scope="col">Done by</th>
                    <th scope="col">Details</th>
                </tr>
            </x-slot:head>

                        @foreach ($logs as $log)
                            <tr>
                                <td style="white-space: nowrap">
                                    <span class="d-block">{{ $log->created_at->format('M j, Y') }}</span>
                                    <span class="small text-muted-vd">{{ $log->created_at->format('g:i A') }}</span>
                                </td>
                                <td>
                                    <span class="d-block">
                                        <i class="bi bi-{{ $log->icon() }} me-1 text-muted-vd" aria-hidden="true"></i>
                                        {{ $log->description }}
                                    </span>
                                    <span class="small text-muted-vd">{{ $log->actionLabel() }}</span>
                                </td>
                                <td>
                                    @if ($log->user)
                                        <span class="d-block">{{ $log->user->full_name }}</span>
                                        <span class="small text-muted-vd">{{ $log->user->role->label() }}</span>
                                    @else
                                        <span class="text-muted-vd">Account removed</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if (filled($log->properties))
                                        @foreach ($log->properties as $key => $value)
                                            <span class="d-block text-muted-vd">
                                                <strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong>
                                                {{ is_array($value) ? implode(', ', $value) : $value }}
                                            </span>
                                        @endforeach
                                    @endif

                                    @if ($link = $subjectLink($log))
                                        <a href="{{ $link }}">Open record</a>
                                    @elseif ($log->subject_id)
                                        <span class="text-muted-vd">Record no longer exists</span>
                                    @endif

                                    @if ($log->ip_address)
                                        <span class="d-block text-muted-vd font-monospace">{{ $log->ip_address }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

            <x-slot:below>{{ $logs->links() }}</x-slot:below>
        </x-data-table>

        <p class="form-text">
            Showing {{ $logs->count() }} of {{ number_format($logs->total()) }} entries.
            Passwords and other secrets are never written here.
        </p>
    @endif
@endsection
