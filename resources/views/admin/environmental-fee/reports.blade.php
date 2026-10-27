@extends('layouts.admin')

@section('title', 'Environmental fee collections')

@section('content')
    <x-page-header title="Environmental fee collections"
                   subtitle="Receipts recorded at municipal checkpoints.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.index') }}">The fee</a>
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.lookup') }}">Find a receipt</a>
        <a class="btn btn-primary"
           href="{{ route('admin.environmental-fee.export', ['from' => $from, 'to' => $to]) }}">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV
        </a>
    </x-page-header>

    {{-- The sentence that stops a total being misread. A figure headed
         "collected" is taken as "in the account" by whoever sees it
         next, and this module cannot say that. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">These are recorded receipts, not remittances</strong>
            The totals below are what collectors wrote down at the gates. The portal takes
            no payments for this fee and holds no remittance record, so nothing here says
            the money has reached the municipality. Resort income is not part of these
            figures and never passes through this module.
        </div>
    </div>

    <form method="GET" action="{{ route('admin.environmental-fee.reports') }}"
          class="vd-panel p-3 mb-4 d-flex flex-wrap gap-3 align-items-end">
        <div>
            <label class="form-label" for="field-from">From</label>
            <input type="date" class="form-control" id="field-from" name="from" value="{{ $from }}">
        </div>
        <div>
            <label class="form-label" for="field-to">To</label>
            <input type="date" class="form-control" id="field-to" name="to" value="{{ $to }}">
        </div>
        <button type="submit" class="btn btn-primary">Show</button>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Recorded" :value="\App\Support\Money::peso($total, true)"
                    note="Receipts written, not money banked." icon="receipt" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Receipts" :value="number_format($count)" icon="card-list" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat label="Counted" :value="number_format($quantity)" icon="people" />
        </div>
        <div class="col-sm-6 col-lg-3">
            {{-- Shown separately, never netted off. A total that quietly
                 absorbed its voids would hide how often they happen. --}}
            <x-stat label="Voided" :value="\App\Support\Money::peso($voidedTotal, true)"
                    :note="$voidedCount.' '.Str::plural('receipt', $voidedCount).', not counted above'"
                    icon="x-octagon" />
        </div>
    </div>

    {{-- How the confirmed money came in, which is what a treasurer
         reconciles against: one figure for the cash box, one for the
         GCash account. And beside them, what has not been confirmed. --}}
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-4">
            <x-stat label="Cash at the gates" :value="\App\Support\Money::peso($cashTotal, true)"
                    note="Part of the recorded total." icon="cash-coin" />
        </div>
        <div class="col-sm-6 col-lg-4">
            <x-stat label="GCash confirmed" :value="\App\Support\Money::peso($gcashTotal, true)"
                    note="Seen in the GCash app by your office." icon="qr-code" />
        </div>
        <div class="col-sm-6 col-lg-4">
            @if ($awaitingCount > 0)
                <div class="vd-panel vd-stat vd-stat--with-icon h-100 border-warning">
                    <span class="vd-feature__icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <div class="vd-stat__value">{{ \App\Support\Money::peso($awaitingTotal, true) }}</div>
                        <div class="vd-stat__label">Not yet checked</div>
                        <p class="vd-stat__note">
                            {{ $awaitingCount }} GCash {{ Str::plural('transfer', $awaitingCount) }}
                            <strong>not counted above</strong>.
                            <a href="{{ route('admin.environmental-fee.verify') }}">Check them</a>.
                        </p>
                    </div>
                </div>
            @else
                <x-stat label="Not yet checked" value="—"
                        note="No GCash transfer is waiting." icon="hourglass" />
            @endif
        </div>
    </div>

    {{-- Whether what was collected has actually been turned in. Counts
         only, linking through to the screen that holds the figures:
         collections and handovers are separate records and this page
         must not start reading like a bank statement. --}}
    @if ($remittanceWaiting > 0 || $remittanceDisputed > 0)
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-box-arrow-in-down mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">Handovers outstanding</strong>
                @if ($remittanceWaiting > 0)
                    {{ $remittanceWaiting }} {{ Str::plural('handover', $remittanceWaiting) }}
                    {{ $remittanceWaiting === 1 ? 'is' : 'are' }} waiting to be counted.
                @endif
                @if ($remittanceDisputed > 0)
                    {{ $remittanceDisputed }} {{ Str::plural('handover', $remittanceDisputed) }}
                    did not agree with the receipts.
                @endif
                <a href="{{ route('admin.environmental-fee.remittances.index') }}">Open handovers</a>.
            </div>
        </div>
    @endif

    {{-- Day by day, then month by month. A treasurer reports monthly
         and reconciles daily, so both are here rather than leaving
         somebody to add up thirty rows by hand. --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <h2 class="h5 mb-3">By day</h2>

            @if ($byDay->isEmpty())
                <x-empty-state icon="calendar3" title="Nothing recorded"
                               message="No collections fall inside these dates." />
            @else
                <x-data-table label="Collections by day">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Day</th>
                            <th scope="col">Receipts</th>
                            <th scope="col">People</th>
                            <th scope="col" class="text-end">Total</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($byDay as $row)
                        <tr>
                            <td class="text-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($row->day)->format('D, M j, Y') }}
                            </td>
                            <td>{{ $row->receipts }}</td>
                            <td>{{ $row->people }}</td>
                            <td class="text-end fw-semibold">@peso($row->total, true)</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </div>

        <div class="col-lg-6">
            <h2 class="h5 mb-3">By month</h2>

            @if ($byMonth->isEmpty())
                <x-empty-state icon="calendar-month" title="Nothing recorded"
                               message="No collections fall inside these dates." />
            @else
                <x-data-table label="Collections by month">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Month</th>
                            <th scope="col">Receipts</th>
                            <th scope="col">People</th>
                            <th scope="col" class="text-end">Total</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($byMonth as $row)
                        <tr>
                            <td class="text-nowrap">
                                {{ \Illuminate\Support\Carbon::parse($row->month.'-01')->format('F Y') }}
                            </td>
                            <td>{{ $row->receipts }}</td>
                            <td>{{ $row->people }}</td>
                            <td class="text-end fw-semibold">@peso($row->total, true)</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </div>
    </div>

    {{-- By barangay, through the checkpoint that took the money. The
         unit a municipal report is actually written in. --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <h2 class="h5 mb-3">By barangay</h2>

            @if ($byBarangay->isEmpty())
                <x-empty-state icon="pin-map" title="Nothing recorded"
                               message="No collections fall inside these dates." />
            @else
                <x-data-table label="Collections by barangay">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Barangay</th>
                            <th scope="col">Receipts</th>
                            <th scope="col">People</th>
                            <th scope="col" class="text-end">Total</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($byBarangay as $row)
                        <tr>
                            <td>Brgy. {{ $row->barangay }}</td>
                            <td>{{ $row->receipts }}</td>
                            <td>{{ $row->people }}</td>
                            <td class="text-end fw-semibold">@peso($row->total, true)</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <h2 class="h5 mb-3">By checkpoint</h2>

            @if ($byCheckpoint->isEmpty())
                <x-empty-state icon="geo-alt" title="Nothing recorded"
                               message="No collections fall inside these dates." />
            @else
                <x-data-table label="Collections by checkpoint">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Checkpoint</th>
                            <th scope="col">Receipts</th>
                            <th scope="col">Total</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($byCheckpoint as $row)
                        <tr>
                            <td>
                                {{ $row->checkpoint?->name }}
                                <span class="d-block small text-muted-vd">
                                    Brgy. {{ $row->checkpoint?->barangay }}
                                </span>
                            </td>
                            <td>{{ $row->receipts }}</td>
                            <td class="fw-semibold">@peso($row->total, true)</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </div>

        <div class="col-lg-6">
            <h2 class="h5 mb-3">By collector</h2>

            @if ($byCollector->isEmpty())
                <x-empty-state icon="person-badge" title="Nothing recorded"
                               message="No collections fall inside these dates." />
            @else
                <x-data-table label="Collections by collector">
                    <x-slot:head>
                        <tr>
                            <th scope="col">Collector</th>
                            <th scope="col">Receipts</th>
                            <th scope="col">Total</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($byCollector as $row)
                        <tr>
                            <td>{{ $row->collector?->full_name }}</td>
                            <td>{{ $row->receipts }}</td>
                            <td class="fw-semibold">@peso($row->total, true)</td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </div>
    </div>

    @if ($voided->isNotEmpty())
        <h2 class="h5 mt-4 mb-3">Voided and corrected</h2>

        <x-data-table label="Voided and corrected collections">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Receipt</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Reason</th>
                </tr>
            </x-slot:head>

            @foreach ($voided as $row)
                <tr>
                    <td class="font-monospace small">{{ $row->reference }}</td>
                    <td class="small">{{ $row->receipt_number }}</td>
                    <td class="small">{{ $row->checkpoint?->name }}</td>
                    <td>@peso($row->amount, true)</td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $row->status->badge() }}">
                            {{ $row->status->label() }}
                        </span>
                    </td>
                    <td class="small">
                        {{ $row->void_reason ?? '—' }}
                        @if ($row->voidedBy)
                            <span class="d-block text-muted-vd">{{ $row->voidedBy->full_name }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
