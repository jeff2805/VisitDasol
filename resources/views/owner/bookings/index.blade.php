@extends('layouts.owner')

@section('title', 'Reservations')

@section('content')
    <x-page-header title="Reservations" :subtitle="$resort->name">
        <a class="btn btn-outline-primary" href="{{ route('owner.availability') }}">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Availability
        </a>
    </x-page-header>

    <ul class="nav nav-pills mb-3 gap-1">
        <li class="nav-item">
            <a class="nav-link @if (! $filters['status']) active @endif" href="{{ route('owner.bookings.index') }}">All</a>
        </li>
        @foreach (\App\Enums\BookingStatus::cases() as $case)
            <li class="nav-item">
                <a class="nav-link @if ($filters['status'] === $case) active @endif"
                   href="{{ route('owner.bookings.index', ['status' => $case->value]) }}">
                    {{ $case->label() }}
                    <span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$case->value] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <form method="GET" action="{{ route('owner.bookings.index') }}" class="vd-panel p-3 mb-3">
        @if ($filters['status'])
            <input type="hidden" name="status" value="{{ $filters['status']->value }}">
        @endif
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $filters['q'] }}" placeholder="Reference or guest">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-room">Room type</label>
                <select class="form-select" id="filter-room" name="room">
                    <option value="">All room types</option>
                    @foreach ($rooms as $id => $name)
                        <option value="{{ $id }}" @selected($filters['room']?->id === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-from">Staying from</label>
                <input type="date" class="form-control" id="filter-from" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-to">Staying until</label>
                <input type="date" class="form-control" id="filter-to" name="to" value="{{ $filters['to'] }}">
            </div>
            <div class="col-md-2 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a class="btn btn-light border" href="{{ route('owner.bookings.index') }}">Clear</a>
            </div>
        </div>
    </form>

    @if ($bookings->isEmpty())
        <x-empty-state icon="calendar-check" title="No reservations to show"
                       message="Requests from guests appear here. Waiting requests are listed first so you can answer them quickly." />
    @else
        <x-data-table label="Reservations at your resort" class="text-nowrap">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Guest</th>
                    <th scope="col">Room type</th>
                    <th scope="col">Stay</th>
                    <th scope="col" class="text-end">Total</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($bookings as $booking)
                <tr>
                    <td class="font-monospace small text-nowrap">{{ $booking->booking_reference }}</td>
                    <td>
                        <span class="d-block">{{ $booking->contact_name }}</span>
                        <span class="small text-muted-vd">{{ $booking->guests }} {{ Str::plural('guest', $booking->guests) }}</span>
                    </td>
                    <td>
                        {{ $booking->room?->name }}
                        @if ($booking->rooms_booked > 1)
                            <span class="small text-muted-vd">x{{ $booking->rooms_booked }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        {{ $booking->dateRangeLabel() }}
                        <span class="d-block small text-muted-vd">{{ $booking->durationLabel() }}</span>
                    </td>
                    <td class="text-end text-nowrap">@peso($booking->total_amount)</td>
                    <td><x-status-badge :status="$booking->status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-primary" href="{{ route('owner.bookings.show', $booking) }}">Open</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $bookings->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
