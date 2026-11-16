@extends('layouts.owner')

@section('title', 'Walk-in records')

@section('content')
    <x-page-header title="Walk-in records"
                   :subtitle="$resort->name.' · guests you took at the gate, and what the tourism office has seen.'">
        <a class="btn btn-primary" href="{{ route('owner.walk-ins.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Record a walk-in
        </a>
    </x-page-header>

    {{-- Returned records are the only thing on this page anybody has to act
         on, so they are called out above the list as well as sorted to the
         top of it. --}}
    @if (($counts[\App\Enums\WalkInSubmissionStatus::Returned->value] ?? 0) > 0)
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            <span>
                The tourism office has sent back
                {{ $counts[\App\Enums\WalkInSubmissionStatus::Returned->value] }}
                {{ \Illuminate\Support\Str::plural('record', $counts[\App\Enums\WalkInSubmissionStatus::Returned->value]) }}
                for correction. Open one to see what they asked for.
            </span>
        </div>
    @endif

    <form method="GET" action="{{ route('owner.walk-ins.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="walkin-q">Reference or guest name</label>
                <input type="search" class="form-control" id="walkin-q" name="q"
                       value="{{ $filters['q'] }}" placeholder="VD-… or a name">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label" for="walkin-status">Status</label>
                <select class="form-select" id="walkin-status" name="status">
                    <option value="">Any status</option>
                    @foreach (\App\Enums\WalkInSubmissionStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected($filters['status'] === $case)>
                            {{ $case->label() }}@if (($counts[$case->value] ?? 0) > 0) ({{ $counts[$case->value] }})@endif
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="walkin-from">Staying from</label>
                <input type="date" class="form-control" id="walkin-from" name="from" value="{{ $filters['from'] }}">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label" for="walkin-to">Staying to</label>
                <input type="date" class="form-control" id="walkin-to" name="to" value="{{ $filters['to'] }}">
            </div>

            <div class="col-6 col-md-1 d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </form>

    @if ($records->isEmpty())
        <x-empty-state icon="person-walking" title="No walk-in records yet"
                       message="Write down a guest who booked at your gate. The room is held straight away, and you can send the record to the tourism office when you are ready.">
            <a class="btn btn-primary" href="{{ route('owner.walk-ins.create') }}">Record your first walk-in</a>
        </x-empty-state>
    @else
        <x-data-table label="Walk-in records">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Guest</th>
                    <th scope="col">Room</th>
                    <th scope="col">Stay</th>
                    <th scope="col" class="text-end">Guests</th>
                    <th scope="col">Office</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach ($records as $record)
                <tr>
                    <td class="font-monospace">{{ $record->booking_reference }}</td>
                    <td>
                        <span class="d-block">{{ $record->contact_name }}</span>
                        <span class="small text-muted-vd">{{ $record->contact_phone }}</span>
                    </td>
                    <td>{{ $record->rooms_booked }} &times; {{ $record->room?->name }}</td>
                    <td>
                        {{-- Plain here, not <x-stay-dates>: that component
                             emits <dt>/<dd> for a definition list and has no
                             place in a table cell. A tour shows one date,
                             matching how it is stored and sold. --}}
                        @if ($record->booking_type->isOvernight())
                            {{ $record->check_in_date->format('M j') }} &ndash;
                            {{ $record->check_out_date->format('M j, Y') }}
                            <span class="d-block small text-muted-vd">
                                {{ $record->nights }} {{ Str::plural('night', $record->nights) }}
                            </span>
                        @else
                            {{ $record->check_in_date->format('M j, Y') }}
                            <span class="d-block small text-muted-vd">{{ $record->booking_type->label() }}</span>
                        @endif
                    </td>
                    <td class="text-end"><x-guest-party :booking="$record" /></td>
                    <td><x-status-badge :status="$record->submission_status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light border" href="{{ route('owner.walk-ins.show', $record) }}">
                            View
                        </a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $records->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
