@extends('layouts.admin')

@section('title', 'Handovers')

@section('content')
    <x-page-header title="Handovers"
                   subtitle="What the gates turned in, and what it came to when counted.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.reports') }}">Collections</a>
        <a class="btn btn-light border"
           href="{{ route('admin.environmental-fee.remittances.export', request()->only('status')) }}">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Download CSV
        </a>
    </x-page-header>

    {{-- Said here as well as on the slip. This screen is where somebody
         could most easily start treating a counted figure as banked. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">These are handovers, not deposits</strong>
            A row here says a collector turned money in and an officer counted it.
            Visit Dasol cannot see a bank account, so nothing here says the money
            reached the treasury. It is also no part of any resort's income.
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <x-stat label="Waiting to be counted" :value="$waitingCount"
                    icon="hourglass-split"
                    note="Handed in, not yet checked by the office." />
        </div>
        <div class="col-sm-4">
            <x-stat label="Value waiting" :value="'₱'.number_format((float) $waitingTotal, 2)"
                    icon="cash-stack"
                    note="As declared by the collectors, before counting." />
        </div>
        <div class="col-sm-4">
            <x-stat label="Does not agree" :value="$disputedCount"
                    icon="exclamation-octagon"
                    note="Counted, and the figures did not match." />
        </div>
    </div>

    {{-- A plain GET form, so filtering works with no script and the
         result is a shareable URL. --}}
    <form method="GET" class="vd-filters mb-3">
        <label class="form-label" for="field-status">Show</label>
        <select class="form-select form-select-sm" id="field-status" name="status" data-auto-submit>
            <option value="">All handovers</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <noscript>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        </noscript>
    </form>

    @if ($remittances->isEmpty())
        <x-empty-state icon="inbox" title="Nothing handed in yet"
                       message="When a collector turns in a period's takings it appears here to be counted." />
    @else
        <x-data-table>
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Collector</th>
                    <th scope="col">Period</th>
                    <th scope="col" class="text-end">Expected</th>
                    <th scope="col" class="text-end">Declared</th>
                    <th scope="col" class="text-end">Counted</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Open</th>
                </tr>
            </x-slot:head>

            @foreach ($remittances as $remittance)
                <tr>
                    <td class="font-monospace small text-nowrap">{{ $remittance->reference }}</td>
                    <td>
                        {{ $remittance->checkpoint?->name }}
                        <span class="d-block small text-muted-vd">
                            Brgy. {{ $remittance->checkpoint?->barangay }}
                        </span>
                    </td>
                    <td>
                        {{ $remittance->collector?->first_name }} {{ $remittance->collector?->last_name }}
                    </td>
                    <td class="text-nowrap small">
                        {{ $remittance->period_start->format('M j') }} to
                        {{ $remittance->period_end->format('M j, Y') }}
                        <span class="d-block text-muted-vd">
                            {{ $remittance->expected_receipts }}
                            {{ Str::plural('receipt', $remittance->expected_receipts) }}
                        </span>
                    </td>
                    <td class="text-end text-nowrap">@peso($remittance->expected_amount, true)</td>
                    <td class="text-end text-nowrap">@peso($remittance->declared_amount, true)</td>
                    <td class="text-end text-nowrap">
                        @if ($remittance->counted_amount === null)
                            <span class="text-muted-vd">&mdash;</span>
                        @else
                            @peso($remittance->counted_amount, true)
                            @php($variance = $remittance->variance())
                            @if ((int) round((float) $variance * 100) !== 0)
                                <span class="d-block small text-danger">
                                    {{ (float) $variance < 0 ? '−' : '+' }}@peso(abs((float) $variance), true)
                                </span>
                            @endif
                        @endif
                    </td>
                    <td><x-status-badge :status="$remittance->status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('admin.environmental-fee.remittances.show', $remittance) }}">
                            View
                        </a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>
                {{ $remittances->links() }}
            </x-slot:below>
        </x-data-table>
    @endif
@endsection
