@extends('layouts.owner')

@section('title', 'Statistics')

@php($peso = fn ($amount) => \App\Support\Money::peso($amount))

@section('content')
    <x-page-header title="Your statistics"
                   :subtitle="$resort->name.' · '.$from->format('M Y').' to '.$to->format('M Y')">
        @foreach ([3 => '3 months', 6 => '6 months', 12 => '12 months', 24 => '2 years'] as $value => $label)
            <a class="btn btn-sm {{ $months === $value ? 'btn-primary' : 'btn-light border' }}"
               href="{{ route('owner.statistics', ['months' => $value]) }}">{{ $label }}</a>
        @endforeach
    </x-page-header>

    {{-- Every tile through <x-stat>. All seven were built by hand here, with
         the note stuffed inside the label as a <span class="small"> — which is
         why these notes were a shade different from the ones the component
         renders on the dashboard beside them. --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Reservations', number_format($summary['reservations']), 'requests in this period'],
            ['Confirmed stays', number_format($summary['confirmed_or_completed']), 'confirmed or completed'],
            ['Guests', number_format($summary['guests']), 'on confirmed stays'],
            ['Room-nights', number_format($summary['room_nights']), 'booked in this period'],
        ] as [$label, $value, $note])
            <div class="col-6 col-xl-3">
                <x-stat :value="$value" :label="$label" :note="$note" />
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <x-stat :value="$peso($summary['value'])" label="Expected income"
                    note="from confirmed and completed stays" />
        </div>
        <div class="col-md-4">
            {{-- A real but tiny figure must not read as nothing at all. --}}
            <x-stat :value="$summary['occupancy_rate'] === 0 && $summary['room_nights'] > 0
                        ? '<1%'
                        : $summary['occupancy_rate'].'%'"
                    label="Occupancy" note="of your rooms on offer" />
        </div>
        <div class="col-md-4">
            <x-stat :value="$summary['cancellation_rate'].'%'" label="Fell through"
                    note="cancelled or declined" />
        </div>
    </div>

    <p class="form-text mb-4">
        Expected income is what guests would pay at your listed rates. Visit Dasol takes no payment,
        so it is a guide, not a record of what you collected.
    </p>

    <div class="row g-4">
        <div class="col-xl-8">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">Reservations and guests per month</h2>
                <x-chart type="line"
                         :labels="$trend->pluck('label')"
                         :series="[
                             ['label' => 'Reservations', 'data' => $trend->pluck('reservations')],
                             ['label' => 'Guests', 'data' => $trend->pluck('guests'), 'color' => '#F2B134'],
                         ]"
                         height="17rem"
                         title="Reservations and guests per month" />
            </section>
        </div>

        <div class="col-xl-4">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">How requests ended</h2>
                <x-chart type="doughnut"
                         :labels="collect(\App\Enums\BookingStatus::cases())->map(fn ($case) => $case->label())"
                         :series="[['label' => 'Reservations', 'data' => array_values($mix)]]"
                         height="17rem"
                         title="Reservations by status" />
            </section>
        </div>
    </div>

    @if ($origins->isNotEmpty())
        <section class="vd-panel p-4 mt-4">
            <h2 class="h5 mb-3">Where your guests come from</h2>
            <x-chart type="bar"
                     :labels="$origins->pluck('place')"
                     :series="[['label' => 'Guests', 'data' => $origins->pluck('guests'), 'color' => '#0D3B47']]"
                     height="15rem"
                     title="Where your guests come from" />
        </section>
    @endif

    <section class="mt-4" aria-labelledby="room-types-title">
        <h2 class="h5 mb-3" id="room-types-title">Your room types</h2>

        <x-data-table label="Your room types" :compact="true">
            <x-slot:head>
                <tr>
                    <th scope="col">Room type</th>
                    <th scope="col" class="text-end">Units</th>
                    <th scope="col" class="text-end">Guests per room</th>
                    <th scope="col" class="text-end">Rate</th>
                </tr>
            </x-slot:head>

            @foreach ($rooms as $room)
                <tr>
                    <td>{{ $room->name }}</td>
                    <td class="text-end">{{ $room->total_rooms }}</td>
                    <td class="text-end">{{ $room->capacity }}</td>
                    <td class="text-end">@peso($room->price_per_night)</td>
                </tr>
            @endforeach
        </x-data-table>
    </section>
@endsection
