@extends('layouts.admin')

@section('title', 'Find a receipt')

@section('content')
    <x-page-header title="Find a receipt"
                   subtitle="Look a collection up by its receipt number or reference.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.reports') }}">Collections</a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.environmental-fee.lookup') }}"
          class="vd-panel p-3 mb-4 d-flex flex-wrap gap-3 align-items-end">
        <div class="flex-grow-1" style="min-width: 14rem">
            <label class="form-label" for="field-q">Receipt number or reference</label>
            <input type="search" class="form-control" id="field-q" name="q"
                   value="{{ $term }}" placeholder="e.g. 004512 or VDE-261024-AB3KQ9">
        </div>
        <button type="submit" class="btn btn-primary">Find it</button>
    </form>

    @if ($term === '')
        <x-empty-state icon="search" title="Enter a number"
                       message="Receipt numbers are unique, so one number finds one collection." />
    @elseif ($results->isEmpty())
        <x-empty-state icon="question-circle" title="Nothing found"
                       message="No collection carries that receipt number or reference. Check the digits against the paper copy." />
    @else
        <x-data-table label="Receipt search results">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Receipt</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Collector</th>
                    <th scope="col">Collected</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Status</th>
                </tr>
            </x-slot:head>

            @foreach ($results as $collection)
                <tr>
                    <td>
                        <a class="font-monospace small"
                           href="{{ route('environmental-fee.show', $collection) }}">
                            {{ $collection->reference }}
                        </a>
                    </td>
                    <td class="small">{{ $collection->receipt_number }}</td>
                    <td class="small">{{ $collection->checkpoint?->name }}</td>
                    <td class="small">{{ $collection->collector?->full_name }}</td>
                    <td class="small">{{ $collection->collected_at->format('M j, Y g:i A') }}</td>
                    <td class="fw-semibold">@peso($collection->amount, true)</td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $collection->status->badge() }}">
                            {{ $collection->status->label() }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
