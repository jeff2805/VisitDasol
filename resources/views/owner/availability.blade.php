@extends('layouts.owner')

@section('title', 'Availability')

@php($days = collect(range(1, $month->daysInMonth))->map(fn ($day) => $month->setDay($day)))

@section('content')
    <x-page-header :title="$month->format('F Y')" :subtitle="$resort->name.' · rooms still free on each night'">
        <a class="btn btn-light border" href="{{ route('owner.availability', ['month' => $previousMonth]) }}">
            <i class="bi bi-chevron-left" aria-hidden="true"></i> {{ \Illuminate\Support\Carbon::parse($previousMonth.'-01')->format('M') }}
        </a>
        <a class="btn btn-light border" href="{{ route('owner.availability', ['month' => $nextMonth]) }}">
            {{ \Illuminate\Support\Carbon::parse($nextMonth.'-01')->format('M') }} <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
        <a class="btn btn-outline-primary" href="{{ route('owner.bookings.index') }}">Reservations</a>
    </x-page-header>

    @if ($rooms->isEmpty())
        <x-empty-state icon="door-open" title="No bookable room types"
                       message="Add a room type, or switch one back on, to see its availability here.">
            <a class="btn btn-primary" href="{{ route('owner.rooms.index') }}">Manage rooms</a>
        </x-empty-state>
    @else
        <p class="text-muted-vd">
            Each number is how many rooms of that type are still free for that night.
            Confirmed stays and requests still waiting for your answer both hold rooms.
        </p>

        <section class="vd-panel">
            <x-scroll-region label="Availability calendar">
                <table class="table table-sm align-middle mb-0" style="min-width: 48rem">
                    <thead>
                        <tr>
                            <th scope="col" style="position: sticky; left: 0; background: #F0F6F5; min-width: 11rem">Room type</th>
                            @foreach ($days as $day)
                                <th scope="col" class="text-center @if ($day->isWeekend()) table-active @endif" style="width: 2.4rem">
                                    <span class="d-block small text-muted-vd">{{ $day->format('D')[0] }}</span>
                                    {{ $day->day }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rooms as $room)
                            <tr>
                                <th scope="row" class="fw-semibold" style="position: sticky; left: 0; background: #FFFFFF">
                                    {{ $room->name }}
                                    <span class="d-block small text-muted-vd fw-normal">{{ $room->total_rooms }} {{ Str::plural('unit', $room->total_rooms) }}</span>
                                </th>
                                @foreach ($days as $day)
                                    @php($free = $calendar[$room->id][$day->toDateString()] ?? $room->total_rooms)
                                    <td class="text-center @if ($day->isBefore(today())) opacity-50 @endif"
                                        title="{{ $day->format('M j') }}: {{ $free }} free of {{ $room->total_rooms }}">
                                        @if ($free === 0)
                                            <span class="vd-badge vd-badge--inactive" style="padding: .1rem .4rem">0</span>
                                        @elseif ($free < $room->total_rooms)
                                            <span class="vd-badge vd-badge--pending" style="padding: .1rem .4rem">{{ $free }}</span>
                                        @else
                                            <span class="text-muted-vd">{{ $free }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-scroll-region>
        </section>

        <p class="vd-map-legend mt-3">
            <span><span class="vd-badge vd-badge--inactive" style="padding:.1rem .4rem">0</span> fully booked</span>
            <span><span class="vd-badge vd-badge--pending" style="padding:.1rem .4rem">1</span> partly booked</span>
            <span class="text-muted-vd">plain numbers mean nothing is booked yet</span>
        </p>
    @endif
@endsection
