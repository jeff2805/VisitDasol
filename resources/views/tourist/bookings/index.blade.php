@extends('layouts.public')

@section('title', 'My reservations')

@section('content')
    <div class="container py-5">
        <x-page-header title="My reservations" subtitle="Requests you have sent and their status.">
            <a class="btn btn-outline-primary" href="{{ route('resorts.index') }}">Browse rooms</a>
        </x-page-header>

        @if ($bookings->isEmpty())
            <x-empty-state icon="calendar-check" title="No reservations yet"
                           message="Find a room, choose your dates, and send a request. It appears here with its status.">
                <a class="btn btn-primary" href="{{ route('resorts.index') }}">Browse rooms</a>
            </x-empty-state>
        @else
            <x-data-table label="My reservations" class="text-nowrap">
                <x-slot:head>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Resort</th>
                        <th scope="col">Stay</th>
                        <th scope="col">Guests</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </x-slot:head>

                @foreach ($bookings as $booking)
                    <tr>
                        <td class="font-monospace small text-nowrap">{{ $booking->booking_reference }}</td>
                        <td>
                            <a class="d-block fw-semibold text-decoration-none"
                               href="{{ route('resorts.show', $booking->resort) }}">{{ $booking->resort->name }}</a>
                            <span class="small text-muted-vd">{{ $booking->room->name }}</span>
                        </td>
                        <td class="text-nowrap">
                            {{ $booking->dateRangeLabel() }}
                            <span class="d-block small text-muted-vd">
                                {{ $booking->durationLabel() }},
                                {{ $booking->rooms_booked }} {{ Str::plural('room', $booking->rooms_booked) }}
                            </span>
                        </td>
                        <td>{{ $booking->guests }}</td>
                        <td class="text-end text-nowrap">@peso($booking->total_amount)</td>
                        <td><x-status-badge :status="$booking->status" /></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('tourist.bookings.show', $booking) }}">
                                View
                            </a>
                        </td>
                    </tr>
                @endforeach

                <x-slot:below>{{ $bookings->links() }}</x-slot:below>
            </x-data-table>

            <p class="text-muted-vd small mt-3">
                Open a reservation to see the full details, the resort's contact number, and to cancel if your plans change.
            </p>
        @endif
    </div>
@endsection
