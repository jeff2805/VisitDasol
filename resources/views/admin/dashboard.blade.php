@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    {{-- The greeting banner: a photograph of Dasol, darkened on the reading side. --}}
    <section class="vd-greeting">
        <img class="vd-greeting__photo" src="{{ asset('images/image1.png') }}" alt="" width="1600" height="900">

        <div class="vd-greeting__body">
            <p class="vd-greeting__place">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                {{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}
            </p>

            <h1 class="vd-greeting__title">
                <span class="vd-greeting__hello">Welcome back,</span>
                {{ auth()->user()->first_name }}!
            </h1>

            <p class="vd-greeting__lead">
                Monitor, manage, and support Dasol's tourism — resorts, reservations,
                and the people behind them — in one place.
            </p>
        </div>

        <p class="vd-greeting__today">
            <span class="vd-greeting__today-day">{{ now()->format('l') }}</span>
            <span class="vd-greeting__today-date">{{ now()->format('F j, Y') }}</span>
        </p>
    </section>

    {{-- Only when there is something to do; an empty strip would be noise. --}}
    @if ($pendingOwnerCount > 0 || $pendingResortCount > 0)
        <div class="vd-todo">
            <i class="bi bi-inboxes-fill" aria-hidden="true"></i>
            <span class="vd-todo__text">Applications are waiting for your review.</span>

            @if ($pendingOwnerCount > 0)
                <a class="btn btn-sm btn-accent" href="{{ route('admin.owner-applications.index') }}">
                    {{ $pendingOwnerCount }} owner {{ Str::plural('application', $pendingOwnerCount) }}
                </a>
            @endif

            @if ($pendingResortCount > 0)
                <a class="btn btn-sm btn-accent" href="{{ route('admin.resort-applications.index') }}">
                    {{ $pendingResortCount }} resort {{ Str::plural('application', $pendingResortCount) }}
                </a>
            @endif
        </div>
    @endif

    {{-- Headline figures --}}
    <div class="row g-3 mb-3">
        @foreach ($metrics as $metric)
            <div class="col-6 col-xl-3">
                <a class="vd-metric vd-metric--{{ $metric['tone'] }}" href="{{ $metric['href'] }}">
                    <span class="vd-metric__icon"><i class="bi bi-{{ $metric['icon'] }}" aria-hidden="true"></i></span>

                    <span class="vd-metric__body">
                        <span class="vd-metric__label">
                            {{ $metric['label'] }}
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </span>

                        <span class="vd-metric__value">{{ number_format($metric['total']) }}</span>

                        @if ($metric['change'] !== null)
                            <span @class(['vd-metric__trend', 'is-down' => $metric['change'] < 0])>
                                <i class="bi bi-arrow-{{ $metric['change'] < 0 ? 'down' : 'up' }}" aria-hidden="true"></i>
                                {{ abs($metric['change']) }}% this month
                            </span>
                        @elseif ($metric['added'] > 0)
                            <span class="vd-metric__trend">
                                <i class="bi bi-arrow-up" aria-hidden="true"></i>
                                {{ number_format($metric['added']) }} new this month
                            </span>
                        @else
                            <span class="vd-metric__trend is-flat">No change this month</span>
                        @endif
                    </span>
                </a>
            </div>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <section class="vd-panel vd-board h-100">
                <header class="vd-board__head">
                    <h2 class="vd-board__title">Tourist arrivals (monthly)</h2>
                    @include('admin.partials.year-filter', ['id' => 'arrivalsYear', 'label' => 'Year of arrivals to show'])
                </header>

                <div class="vd-board__body">
                    @if ($arrivals['total'] > 0)
                        <x-chart type="line"
                                 :labels="$arrivals['labels']"
                                 :series="[['label' => 'Guests', 'data' => $arrivals['data'], 'color' => '#0F7C7A']]"
                                 :legend="false"
                                 :points="0"
                                 fill="gradient"
                                 suffix=" guests"
                                 height="17rem"
                                 title="Guests arriving each month of {{ $year }}" />
                    @else
                        <p class="vd-board__blank">No confirmed stays fall in {{ $year }} yet.</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="vd-panel vd-board h-100">
                <header class="vd-board__head">
                    <h2 class="vd-board__title">Bookings by status</h2>
                    @include('admin.partials.year-filter', ['id' => 'mixYear', 'label' => 'Year of reservations to show'])
                </header>

                <div class="vd-board__body">
                    @if ($bookingMix['total'] > 0)
                        <div class="vd-mix">
                            <div class="vd-donut">
                                <x-chart type="doughnut"
                                         :labels="collect($bookingMix['rows'])->pluck('label')->all()"
                                         :series="[[
                                             'label' => 'Reservations',
                                             'data' => collect($bookingMix['rows'])->pluck('count')->all(),
                                             'colors' => collect($bookingMix['rows'])->pluck('color')->all(),
                                         ]]"
                                         :legend="false"
                                         cutout="72%"
                                         height="11.5rem"
                                         title="Reservations by status in {{ $year }}" />

                                <p class="vd-donut__center">
                                    <span class="vd-donut__total">{{ number_format($bookingMix['total']) }}</span>
                                    <span class="vd-donut__caption">Total</span>
                                </p>
                            </div>

                            <ul class="vd-mix__key">
                                @foreach ($bookingMix['rows'] as $row)
                                    <li class="vd-mix__row">
                                        <span class="vd-mix__dot" style="background: {{ $row['color'] }}"></span>
                                        <span class="vd-mix__label">{{ $row['label'] }}</span>
                                        <span class="vd-mix__count">{{ number_format($row['count']) }}</span>
                                        <span class="vd-mix__share">{{ $row['share'] }}%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <p class="vd-board__blank">No reservations fall in {{ $year }} yet.</p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- Recent activity --}}
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <section class="vd-panel vd-board h-100">
                <header class="vd-board__head">
                    <h2 class="vd-board__title">Recent bookings</h2>
                    <a class="vd-board__more" href="{{ route('admin.bookings.index') }}">
                        View all <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </header>

                @if ($recentBookings->isEmpty())
                    <p class="vd-board__blank">No reservations have been made yet.</p>
                @else
                    {{-- data-label turns each row into a labelled stack on a phone;
                         see .vd-stack-table in the stylesheet. --}}
                    <x-scroll-region label="Recent bookings">
                        <table class="table vd-board__table vd-stack-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Guest</th>
                                    <th scope="col">Resort</th>
                                    <th scope="col">Check-in</th>
                                    <th scope="col">Check-out</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentBookings as $booking)
                                    <tr>
                                        <td>
                                            <a class="fw-semibold" href="{{ route('admin.bookings.show', $booking) }}">
                                                {{ $booking->tourist?->full_name ?? $booking->contact_name }}
                                            </a>
                                            <span class="d-block small text-muted-vd">{{ $booking->booking_reference }}</span>
                                        </td>
                                        <td data-label="Resort">{{ $booking->resort?->name }}</td>
                                        <td data-label="Check-in">{{ $booking->check_in_date->format('M j, Y') }}</td>
                                        <td data-label="Check-out">{{ $booking->check_out_date->format('M j, Y') }}</td>
                                        <td data-label="Status"><x-status-badge :status="$booking->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-scroll-region>
                @endif
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="vd-panel vd-board h-100">
                <header class="vd-board__head">
                    <h2 class="vd-board__title">Top resorts</h2>
                    <a class="vd-board__more" href="{{ route('admin.resorts.index') }}">
                        View all <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </header>

                @if ($topResorts->isEmpty())
                    <p class="vd-board__blank">No resort has taken a booking yet.</p>
                @else
                    <ol class="vd-rank">
                        @foreach ($topResorts as $index => $resort)
                            <li class="vd-rank__row">
                                <span class="vd-rank__medal" aria-hidden="true">{{ $index + 1 }}</span>

                                <img class="vd-rank__photo" src="{{ $resort->main_image_url }}" alt="" loading="lazy">

                                <span class="vd-rank__who">
                                    <a class="vd-rank__name" href="{{ route('admin.resorts.show', $resort) }}">{{ $resort->name }}</a>
                                    <span class="vd-rank__where">Brgy. {{ $resort->barangay }}</span>
                                </span>

                                <span class="vd-rank__score">
                                    <span class="vd-rank__count">{{ number_format($resort->bookings_count) }}</span>
                                    <span class="vd-rank__unit">{{ Str::plural('booking', $resort->bookings_count) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
@endsection
