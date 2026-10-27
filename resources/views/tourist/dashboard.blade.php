{{-- The tourist's home after signing in: their stay, then where to go next. --}}
@extends('layouts.public')

@section('title', 'My account')

@section('content')
    <section class="vd-welcome" aria-labelledby="welcome-title">
        <div class="vd-saltbeds" aria-hidden="true"></div>

        <div class="container position-relative">
            <p class="vd-welcome__eyebrow">{{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}</p>
            <h1 class="vd-welcome__title" id="welcome-title">Hello, {{ $user->first_name }}</h1>
            <p class="vd-welcome__lead">
                @if ($stats['upcoming'] > 0)
                    You have {{ $stats['upcoming'] }} {{ Str::plural('stay', $stats['upcoming']) }} coming up on Dasol Bay.
                @elseif ($stats['pending'] > 0)
                    {{ $stats['pending'] }} {{ Str::plural('request', $stats['pending']) }} {{ $stats['pending'] === 1 ? 'is' : 'are' }} with the resort, waiting for a reply.
                @else
                    {{-- Not the home page's sentence repeated: that one
                         introduces the site to a stranger, and this one is read
                         by somebody who already has an account. --}}
                    Pick your dates and see which rooms are free on Dasol Bay.
                @endif
            </p>

            {{-- The same search a signed-out visitor gets on the home page.
                 Without it a guest who had taken the trouble to make an account
                 arrived somewhere with fewer ways to find a room than a
                 stranger, and had to go back out to the public pages to start. --}}
            <x-stay-search :barangays="config('visitdasol.barangays')" class="mt-4" />

            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-outline-salt" href="{{ route('resorts.index') }}">Browse every room</a>
                <a class="btn btn-outline-salt" href="{{ route('map') }}">Open the map</a>
            </div>
        </div>

        <x-shore-curve />
    </section>

    <div class="container vd-section pt-4">
        {{-- What is happening with my reservations ---------------------------- --}}
        <div class="row g-3 mb-4">
            @foreach ([
                ['value' => $stats['upcoming'], 'label' => 'Upcoming ' . Str::plural('stay', $stats['upcoming']), 'icon' => 'calendar-check'],
                ['value' => $stats['pending'], 'label' => 'Waiting for a reply', 'icon' => 'hourglass-split'],
                ['value' => $stats['completed'], 'label' => 'Completed ' . Str::plural('stay', $stats['completed']), 'icon' => 'check2-circle'],
            ] as $tile)
                <div class="col-sm-4">
                    <x-stat :value="$tile['value']" :label="$tile['label']" :icon="$tile['icon']" />
                </div>
            @endforeach
        </div>

        {{-- The next stay, in full -------------------------------------------- --}}
        <section class="mb-5" aria-labelledby="next-stay-title">
            <div class="vd-page-header">
                <div>
                    <h2 id="next-stay-title" class="vd-page-header__title">Your next stay</h2>
                    <p class="vd-page-header__subtitle">The reservation the resort has already confirmed.</p>
                </div>
                {{-- The way through to the whole list belongs on the list
                     below, not on this one stay. --}}
            </div>

            @if ($nextStay === null)
                <x-empty-state icon="calendar-heart" title="No confirmed stay yet"
                               message="Once a resort confirms a request, the dates and details appear here.">
                    <a class="btn btn-primary" href="{{ route('resorts.index') }}">Find a room</a>
                </x-empty-state>
            @else
                <article class="vd-panel vd-next-stay">
                    <div class="vd-next-stay__main">
                        <p class="vd-card__eyebrow mb-1">{{ $nextStay->booking_reference }}</p>
                        <h3 class="h4 mb-1">
                            <a class="text-decoration-none" href="{{ route('resorts.show', $nextStay->resort) }}">{{ $nextStay->resort->name }}</a>
                        </h3>
                        <p class="vd-card__meta mb-3">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            Brgy. {{ $nextStay->resort->barangay }}, {{ $nextStay->resort->municipality }}
                        </p>

                        <dl class="vd-next-stay__facts">
                            <div>
                                <dt>Check in</dt>
                                <dd>{{ $nextStay->check_in_date->format('D, M j, Y') }}</dd>
                            </div>
                            <div>
                                <dt>Check out</dt>
                                <dd>{{ $nextStay->check_out_date->format('D, M j, Y') }}</dd>
                            </div>
                            <div>
                                <dt>Room</dt>
                                <dd>{{ $nextStay->room->name }}</dd>
                            </div>
                            <div>
                                <dt>Guests</dt>
                                <dd>{{ $nextStay->guests }}</dd>
                            </div>
                        </dl>
                    </div>

                    <aside class="vd-next-stay__side">
                        <x-status-badge :status="$nextStay->status" />

                        @php
                            // Carbon 3 returns a signed float, so take the direction
                            // explicitly: today -> check-in, never the other way round.
                            $daysAway = (int) today()->diffInDays($nextStay->check_in_date);
                        @endphp
                        <p class="vd-next-stay__countdown">
                            @if ($nextStay->check_in_date->isToday())
                                Check in today
                            @elseif ($daysAway > 0)
                                in {{ $daysAway }} {{ Str::plural('day', $daysAway) }}
                            @else
                                Staying now
                            @endif
                        </p>

                        <p class="vd-next-stay__total">
                            <span class="text-muted-vd small d-block">Total</span>
                            <strong class="vd-card__price">@peso($nextStay->total_amount)</strong>
                            <span class="text-muted-vd small">for {{ $nextStay->nights }} {{ Str::plural('night', $nextStay->nights) }}</span>
                        </p>

                        {{-- What is not paid yet, when it can be paid here. The
                             panel used to show the total and stop, so a guest
                             with a confirmed, unpaid stay learned nothing about
                             it until they opened the reservation.

                             Worded as an offer, not a demand: the reservation
                             page says plainly that paying now is optional and
                             the resort will still take the money on arrival, so
                             a dashboard that read "you owe this" would frighten
                             people the booking itself is relaxed about. --}}
                        @if ($dueOnNextStay !== null)
                            <p class="vd-next-stay__due">
                                <i class="bi bi-wallet2" aria-hidden="true"></i>
                                <span>
                                    <strong>@peso($dueOnNextStay)</strong> not paid yet.
                                    Pay online now, or settle at the resort.
                                </span>
                            </p>

                            <a class="btn btn-accent w-100 mb-2" href="{{ route('tourist.bookings.show', $nextStay) }}#pay-title">
                                Pay online
                            </a>
                            <a class="btn btn-outline-primary w-100" href="{{ route('tourist.bookings.show', $nextStay) }}">View reservation</a>
                        @else
                            <a class="btn btn-primary w-100" href="{{ route('tourist.bookings.show', $nextStay) }}">View reservation</a>
                        @endif
                    </aside>
                </article>
            @endif
        </section>

        {{-- Recent reservations ------------------------------------------------ --}}
        @if ($recentBookings->isNotEmpty())
            <section class="mb-5" aria-labelledby="recent-title">
                <div class="vd-page-header">
                    <div>
                        <h2 id="recent-title" class="vd-page-header__title h5">Recent reservations</h2>
                        <p class="vd-page-header__subtitle">The last four, newest first.</p>
                    </div>
                    {{-- One name for this page everywhere it is named: here, in
                         the account menu, and on the page's own heading. --}}
                    <a class="btn btn-outline-primary" href="{{ route('tourist.bookings.index') }}">My reservations</a>
                </div>

                {{-- The shared table shell, like every other table on the site.
                     This was the last one still hand-written, which is why it
                     alone had no hover row and no keyboard-reachable scroll
                     region when the columns did not fit. --}}
                <x-data-table label="Recent reservations">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Resort</th>
                            <th scope="col">Dates</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($recentBookings as $booking)
                        <tr>
                            <td>
                                <span class="d-block fw-semibold">{{ $booking->resort->name }}</span>
                                <span class="small text-muted-vd">{{ $booking->booking_reference }}</span>
                            </td>
                            <td class="text-nowrap">
                                {{ $booking->check_in_date->format('M j') }} to
                                {{ $booking->check_out_date->format('M j, Y') }}
                            </td>
                            <td><x-status-badge :status="$booking->status" /></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('tourist.bookings.show', $booking) }}">View</a>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>
        @endif

        {{-- Where to go next ---------------------------------------------------- --}}
        @if ($resorts->isNotEmpty())
            <section aria-labelledby="stay-title">
                {{-- This section used to repeat the public home page word for
                     word, down to the subtitle, so signing in showed a guest the
                     page they had just left. The cards are the same because they
                     are the same resorts; the words say what they are doing here,
                     which is suggesting somewhere for a stay that is not booked
                     yet. --}}
                <div class="vd-page-header">
                    <div>
                        <p class="vd-eyebrow">Discover Dasol</p>
                        <h2 id="stay-title" class="vd-page-header__title">Somewhere to stay next</h2>
                        <p class="vd-page-header__subtitle">
                            Resorts the tourism office has featured. Each one lists its own rooms and rates.
                        </p>
                    </div>
                    <a class="btn btn-outline-primary rounded-pill px-4 d-inline-flex align-items-center gap-2" href="{{ route('resorts.index') }}">
                        Every room in Dasol
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="row g-4">
                    @foreach ($resorts as $resort)
                        <div class="col-md-6 col-lg-4">
                            <x-resort-card :resort="$resort" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
