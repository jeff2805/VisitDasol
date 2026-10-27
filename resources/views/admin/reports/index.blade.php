@extends('layouts.admin')

@section('title', 'Reports')

@php
    $peso = fn ($amount) => \App\Support\Money::peso($amount);
    $exportLink = route('admin.reports.export', [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
        'resort' => $resort?->id,
        'basis' => $basis,
    ]);
    $returnLink = route('admin.reports.accommodation', [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
        'resort' => $resort?->id,
    ]);
@endphp

@section('content')
    <x-page-header title="Tourism statistics"
                   :subtitle="$from->format('M j, Y').' to '.$to->format('M j, Y').($resort ? ' · '.$resort->name : ' · all resorts')">
        <a class="btn btn-outline-primary d-print-none" href="{{ $exportLink }}">
            <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Download CSV
        </a>
        <button type="button" class="btn btn-light border d-print-none" data-print>
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
        </button>
    </x-page-header>

    <form method="GET" action="{{ route('admin.reports.index') }}" class="vd-panel p-3 mb-4 d-print-none">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label" for="filter-from">From</label>
                <input type="date" class="form-control" id="filter-from" name="from" value="{{ $from->toDateString() }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-to">To</label>
                <input type="date" class="form-control" id="filter-to" name="to" value="{{ $to->toDateString() }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-resort">Resort</label>
                <select class="form-select" id="filter-resort" name="resort">
                    <option value="">All resorts</option>
                    @foreach ($resorts as $id => $name)
                        <option value="{{ $id }}" @selected($resort?->id === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-basis">Dates refer to</label>
                <select class="form-select" id="filter-basis" name="basis">
                    <option value="stay" @selected($basis === 'stay')>The stay</option>
                    <option value="requested" @selected($basis === 'requested')>When requested</option>
                </select>
            </div>
            <div class="col-md-2 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply</button>
                <a class="btn btn-light border" href="{{ route('admin.reports.index') }}">Reset</a>
            </div>
        </div>
    </form>

    {{-- Headline numbers --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Reservations', $summary['reservations'], 'in this period'],
            ['Confirmed stays', $summary['confirmed_or_completed'], 'confirmed or completed'],
            ['Guests', $summary['guests'], 'on confirmed stays'],
            ['Room-nights', $summary['room_nights'], 'booked within the period'],
        ] as [$label, $value, $note])
            <div class="col-6 col-xl-3">
                <div class="vd-panel vd-stat h-100">
                    <div class="vd-stat__value">{{ number_format($value) }}</div>
                    <div class="vd-stat__label">{{ $label }}<span class="d-block small">{{ $note }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- No takings figure here. What the resorts of Dasol earn is their own
         trade; what the office is here to watch is how busy the town is and
         how many of its rooms are being used. --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-4">
            <div class="vd-panel vd-stat h-100">
                <div class="vd-stat__value">{{ $summary['occupancy_rate'] === 0 && $summary['room_nights'] > 0 ? '<1%' : $summary['occupancy_rate'].'%' }}</div>
                <div class="vd-stat__label">Occupancy<span class="d-block small">of rooms on offer today</span></div>
            </div>
        </div>
        <div class="col-6 col-xl-4">
            <div class="vd-panel vd-stat h-100">
                <div class="vd-stat__value">{{ $summary['cancellation_rate'] }}%</div>
                <div class="vd-stat__label">Fell through<span class="d-block small">cancelled or declined</span></div>
            </div>
        </div>
        <div class="col-6 col-xl-4">
            <div class="vd-panel vd-stat h-100">
                <div class="vd-stat__value">{{ number_format($summary['pending']) }}</div>
                <div class="vd-stat__label">Waiting for a reply<span class="d-block small">resorts have not answered</span></div>
            </div>
        </div>
    </div>

    <p class="form-text mb-4">
        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
        These figures count visitors and room use, not money. What each resort takes is its own
        business and is not reported here; a resort owner sees their own takings on their own
        statistics page. Occupancy compares booked room-nights with the rooms currently on offer.
    </p>

    {{-- The Department of Tourism's monthly accommodation return.

         One row per resort, in the columns the office has to file. Counted
         from ARRIVALS — who checked in during the period — because that is the
         question the form asks, and it is what each resort's guest register
         answers. Everything else on this page reads by the dates of the stay,
         which is why this section says so plainly. --}}
    <section class="vd-panel p-4 mb-4" aria-labelledby="return-title">
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
            <h2 class="h5 mb-0" id="return-title">Visitor register</h2>
            <a class="btn btn-sm btn-primary d-print-none" href="{{ $returnLink }}">
                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Download for Excel
            </a>
        </div>

        <p class="text-muted-vd small">
            One line for every stay that <strong>began</strong> between {{ $from->format('M j, Y') }}
            and {{ $to->format('M j, Y') }}, under the name of the person who booked it. Cancelled
            and declined requests are not visitors and are left out.
        </p>

        @php
            $register = $accommodation;
            $arrivals = $register->sum('guests');
            $stated = $register->sum(fn ($row) => $row['male'] + $row['female']);
        @endphp

        @if ($register->isEmpty())
            <p class="text-muted-vd mb-0">
                Nobody checked in during this period. Change the dates above to look at another month.
            </p>
        @else
            <x-scroll-region label="Visitor register">
                <table class="table table-sm align-middle mb-0 vd-return">
                    <thead>
                        <tr>
                            <th scope="col" class="vd-return__resort">Resort</th>
                            <th scope="col" class="vd-return__guest">Guest</th>
                            <th scope="col" class="vd-return__guest">Check in</th>
                            <th scope="col" class="vd-return__c">C-check in</th>
                            <th scope="col" class="vd-return__o">O-night</th>
                            <th scope="col" class="vd-return__r">R-used</th>
                            <th scope="col" class="vd-return__m">M</th>
                            <th scope="col" class="vd-return__f">F</th>
                            @foreach (\App\Enums\VisitorOrigin::cases() as $case)
                                <th scope="col" class="vd-return__o-{{ $case->value }}">{{ $case->shortLabel() }}</th>
                            @endforeach
                            <th scope="col">Not stated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($register as $row)
                            <tr>
                                <td>
                                    {{ $row['resort'] }}
                                    <span class="d-block small text-muted-vd">{{ $row['room'] }}</span>
                                </td>
                                {{-- The row's subject: whose stay this line is. --}}
                                <th scope="row" class="fw-semibold">
                                    {{ $row['guest'] }}
                                    <span class="d-block small fw-normal text-muted-vd font-monospace">{{ $row['reference'] }}</span>
                                </th>
                                <td class="text-nowrap">
                                    {{ $row['check_in']->format('M j, Y') }}
                                    <span class="d-block small text-muted-vd">
                                        {{ $row['nights'] }} {{ Str::plural('night', $row['nights']) }}
                                    </span>
                                </td>
                                <td class="text-end">{{ number_format($row['guests']) }}</td>
                                <td class="text-end">{{ number_format($row['guest_nights']) }}</td>
                                <td class="text-end">{{ number_format($row['rooms_used']) }}</td>
                                <td class="text-end">{{ number_format($row['male']) }}</td>
                                <td class="text-end">{{ number_format($row['female']) }}</td>
                                @foreach (\App\Enums\VisitorOrigin::cases() as $case)
                                    <td class="text-end">{{ number_format($row['origin'][$case->value]) }}</td>
                                @endforeach
                                <td class="text-end">{{ number_format($row['origin']['not_stated']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        {{-- The totals the office actually files. --}}
                        <tr>
                            <th scope="row" colspan="3">
                                {{ $register->count() }} {{ Str::plural('stay', $register->count()) }}
                            </th>
                            <td class="text-end">{{ number_format($arrivals) }}</td>
                            <td class="text-end">{{ number_format($register->sum('guest_nights')) }}</td>
                            <td class="text-end">{{ number_format($register->sum('rooms_used')) }}</td>
                            <td class="text-end">{{ number_format($register->sum('male')) }}</td>
                            <td class="text-end">{{ number_format($register->sum('female')) }}</td>
                            @foreach (\App\Enums\VisitorOrigin::cases() as $case)
                                <td class="text-end">{{ number_format($register->sum(fn ($row) => $row['origin'][$case->value])) }}</td>
                            @endforeach
                            <td class="text-end">{{ number_format($register->sum(fn ($row) => $row['origin']['not_stated'])) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </x-scroll-region>
        @endif

        {{-- Said plainly rather than hidden, because the office is about to
             file these figures: the sex and origin columns are only as complete
             as what guests chose to answer on the booking form. --}}
        @if ($arrivals > 0 && $stated < $arrivals)
            <p class="form-text mt-3 mb-0">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                {{ number_format($arrivals - $stated) }} of {{ number_format($arrivals) }}
                {{ Str::plural('arrival', $arrivals) }} did not say their sex or where they travelled
                from — the booking form asks, but does not insist. Those are counted under
                <strong>Not stated</strong> rather than spread across the other columns.
            </p>
        @endif
    </section>

    {{-- The reservations the figures above are counted from. The totals say how
         many; this says which ones, which is the first thing anyone asks when a
         number looks wrong. --}}
    <section class="vd-panel p-4 mb-4" aria-labelledby="reservations-title">
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0" id="reservations-title">Reservations in this period</h2>
            {{-- The count comes from the summary, which has already worked it
                 out over exactly this query; asking the paginator for it would
                 run the same COUNT a second time. --}}
            <span class="text-muted-vd small">
                {{ number_format($summary['reservations']) }}
                {{ Str::plural('reservation', $summary['reservations']) }}
                &middot; counted by {{ $basis === 'requested' ? 'the date requested' : 'the dates of the stay' }}
            </span>
        </div>

        @if ($reservations->isEmpty())
            <p class="text-muted-vd mb-0">
                No reservations fall in this period. If you expected some, try
                <strong>Dates refer to &rarr; When requested</strong>: a booking made today for a stay
                next month counts in next month when read by the stay.
            </p>
        @else
            <x-scroll-region label="Reservations in this period">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Guest</th>
                            <th scope="col">Resort</th>
                            <th scope="col">Room</th>
                            <th scope="col">Stay</th>
                            <th scope="col" class="text-end">Nights</th>
                            <th scope="col" class="text-end">Guests</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservations as $booking)
                            <tr>
                                <td class="font-monospace small text-nowrap">
                                    <a href="{{ route('admin.bookings.show', $booking) }}">{{ $booking->booking_reference }}</a>
                                </td>
                                <td>
                                    <span class="d-block">{{ $booking->contact_name }}</span>
                                    {{-- Contact details as given on the booking form. --}}
                                    <span class="small text-muted-vd d-block">{{ $booking->contact_phone }}</span>
                                    @if ($booking->contact_email)
                                        <span class="small text-muted-vd d-block text-break">{{ $booking->contact_email }}</span>
                                    @endif
                                    @if ($booking->tourist?->home_city)
                                        <span class="small text-muted-vd d-block">
                                            From {{ $booking->tourist->home_city }}@if ($booking->tourist->home_country), {{ $booking->tourist->home_country }}@endif
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="d-block">{{ $booking->resort?->name }}</span>
                                    <span class="small text-muted-vd">Brgy. {{ $booking->resort?->barangay }}</span>
                                </td>
                                <td>{{ $booking->rooms_booked }} &times; {{ $booking->room?->name }}</td>
                                <td class="text-nowrap">
                                    {{ $booking->check_in_date->format('M j, Y') }}
                                    <span class="d-block small text-muted-vd">to {{ $booking->check_out_date->format('M j, Y') }}</span>
                                </td>
                                <td class="text-end">{{ $booking->nights }}</td>
                                <td class="text-end">{{ $booking->guests }}</td>
                                <td><x-status-badge :status="$booking->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-scroll-region>

            @if ($reservations->hasPages())
                <div class="mt-3 d-print-none">{{ $reservations->links() }}</div>
            @endif

            <p class="form-text mb-0">
                Special requests, amounts, and the full history of each reservation are on its own page.
                The CSV download above carries every row in this period, not just this page.
            </p>
        @endif
    </section>

    {{-- The same reservations counted per person instead of per booking.
         No money here on purpose: how many visitors came and how often they
         return is the office's business, what each of them paid is the
         resort's. --}}
    <section class="vd-panel p-4 mb-4" aria-labelledby="guests-title">
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0" id="guests-title">Guests who booked</h2>
            <span class="text-muted-vd small">
                {{ number_format($guests->count()) }}
                {{ Str::plural('person', $guests->count()) }} behind
                {{ number_format($summary['reservations']) }}
                {{ Str::plural('reservation', $summary['reservations']) }}
            </span>
        </div>

        @if ($guests->isEmpty())
            <p class="text-muted-vd mb-0">Nobody booked in this period.</p>
        @else
            <x-scroll-region label="Guests who booked">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Guest</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Travelling from</th>
                            <th scope="col" class="text-end">Bookings</th>
                            <th scope="col" class="text-end">Guests</th>
                            <th scope="col" class="text-end">Nights</th>
                            <th scope="col">Latest stay</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($guests as $guest)
                            <tr>
                                <td>{{ $guest['name'] }}</td>
                                <td class="small">
                                    <span class="d-block">{{ $guest['phone'] ?? '—' }}</span>
                                    @if ($guest['email'])
                                        <span class="text-muted-vd text-break">{{ $guest['email'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $guest['place'] ?? '—' }}</td>
                                <td class="text-end">{{ number_format($guest['bookings']) }}</td>
                                <td class="text-end">{{ number_format($guest['guests']) }}</td>
                                <td class="text-end">{{ number_format($guest['nights']) }}</td>
                                <td class="text-nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($guest['latest'])->format('M j, Y') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-scroll-region>

            <p class="form-text mb-0">
                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                Amounts are left out here by design. What a guest paid is between them and the
                resort; this list is for counting visitors, not their spending.
            </p>
        @endif
    </section>

    {{-- Charts --}}
    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">Reservations and guests per month</h2>
                <x-chart type="line"
                         :labels="$trend->pluck('label')"
                         :series="[
                             ['label' => 'Reservations', 'data' => $trend->pluck('reservations')],
                             ['label' => 'Guests', 'data' => $trend->pluck('guests'), 'color' => '#F2B134'],
                         ]"
                         height="18rem"
                         title="Reservations and guests per month" />
            </section>
        </div>

        <div class="col-xl-4">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">How reservations ended</h2>
                <x-chart type="doughnut"
                         :labels="collect(\App\Enums\BookingStatus::cases())->map(fn ($case) => $case->label())"
                         :series="[['label' => 'Reservations', 'data' => array_values($mix)]]"
                         height="18rem"
                         title="Reservations by status" />
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">Busiest resorts</h2>
                @if ($topResorts->isEmpty())
                    <p class="text-muted-vd mb-0">No confirmed stays in this period.</p>
                @else
                    <x-chart type="bar"
                             :labels="$topResorts->pluck('name')"
                             :series="[['label' => 'Confirmed stays', 'data' => $topResorts->pluck('reservations')]]"
                             height="16rem"
                             title="Busiest resorts" />

                    <x-scroll-region label="Busiest resorts" class="mt-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    {{-- Takings deliberately absent: this table names
                                         each resort, so a money column here would set
                                         out what every business in town earns. --}}
                                    <th scope="col">Resort</th>
                                    <th scope="col" class="text-end">Stays</th>
                                    <th scope="col" class="text-end">Guests</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topResorts as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-end">{{ number_format($row['reservations']) }}</td>
                                        <td class="text-end">{{ number_format($row['guests']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-scroll-region>
                @endif
            </section>
        </div>

        <div class="col-xl-5">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">Where visitors come from</h2>
                @if ($origins->isEmpty())
                    <p class="text-muted-vd mb-0">
                        No home towns recorded yet. Guests give this when they register, and it is optional.
                    </p>
                @else
                    <x-chart type="bar"
                             :labels="$origins->pluck('place')"
                             :series="[['label' => 'Guests', 'data' => $origins->pluck('guests'), 'color' => '#0D3B47']]"
                             height="16rem"
                             title="Where visitors come from" />
                @endif
            </section>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">New accounts per month</h2>
                <x-chart type="bar"
                         :labels="$registrations->pluck('label')"
                         :series="[
                             ['label' => 'Tourists', 'data' => $registrations->pluck('tourists')],
                             ['label' => 'Resort owners', 'data' => $registrations->pluck('owners'), 'color' => '#F2B134'],
                         ]"
                         height="16rem"
                         title="New accounts per month" />
            </section>
        </div>

        <div class="col-xl-5">
            <section class="vd-panel p-4 h-100">
                <h2 class="h5 mb-3">The platform today</h2>
                <dl class="row mb-0 small">
                    <dt class="col-8 text-muted-vd fw-normal">Tourists ({{ number_format($totals['active_tourists']) }} active)</dt>
                    <dd class="col-4 text-end">{{ number_format($totals['tourists']) }}</dd>

                    <dt class="col-8 text-muted-vd fw-normal">Resort owners ({{ number_format($totals['pending_owners']) }} waiting)</dt>
                    <dd class="col-4 text-end">{{ number_format($totals['active_owners']) }}</dd>

                    <dt class="col-8 text-muted-vd fw-normal">Resorts visible to visitors</dt>
                    <dd class="col-4 text-end">{{ number_format($totals['public_resorts']) }}</dd>

                    <dt class="col-8 text-muted-vd fw-normal">Resorts waiting for review</dt>
                    <dd class="col-4 text-end">{{ number_format($totals['pending_resorts']) }}</dd>

                    <dt class="col-8 text-muted-vd fw-normal">Bookable room types</dt>
                    <dd class="col-4 text-end">{{ number_format($totals['bookable_rooms']) }}</dd>

                    <dt class="col-8 text-muted-vd fw-normal">Rooms on offer</dt>
                    <dd class="col-4 text-end mb-0">{{ number_format($totals['units']) }}</dd>
                </dl>
            </section>
        </div>
    </div>
@endsection
