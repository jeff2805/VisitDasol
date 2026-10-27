@extends('layouts.admin')

@section('title', 'Barangay officials')

@section('content')
    <x-page-header title="Barangay officials"
                   subtitle="Accounts that may send in destinations for their own barangay.">
        <a class="btn btn-primary" href="{{ route('admin.barangay-officials.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add an official
        </a>
    </x-page-header>

    {{-- Which barangays still have nobody. This is the whole point of the
         screen: an uncovered barangay is one whose places nobody is writing up. --}}
    @php($uncovered = array_values(array_diff($barangays, $covered)))

    @if ($uncovered !== [])
        <div class="alert alert-info" role="status">
            <strong class="d-block mb-1">
                {{ count($uncovered) }} of {{ count($barangays) }}
                {{ \Illuminate\Support\Str::plural('barangay', count($barangays)) }} has nobody yet
            </strong>
            {{ implode(', ', $uncovered) }}
        </div>
    @endif

    @if ($officials->isEmpty())
        <div class="vd-panel p-5 text-center">
            <p class="h5 mb-2">No barangay officials yet</p>
            <p class="text-muted-vd mb-4">
                Dasol has {{ count($barangays) }} barangays and one tourism office. The people who
                know where each place is live in the barangays &mdash; give them an account and
                they can write the destinations up for you.
            </p>
            <a class="btn btn-primary btn-lg" href="{{ route('admin.barangay-officials.create') }}">
                Add the first one
            </a>
        </div>
    @else
        <x-data-table label="Barangay officials">
            <x-slot:head>
                <tr>
                    <th scope="col">Barangay</th>
                    <th scope="col">Official</th>
                    <th scope="col">Account</th>
                    <th scope="col" class="text-end">On the site</th>
                    <th scope="col" class="text-end">Waiting</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </x-slot:head>

            @foreach ($officials as $official)
                <tr>
                    <th scope="row">{{ $official->barangay ?? '—' }}</th>
                    <td>
                        {{ $official->full_name }}
                        <span class="d-block small text-muted-vd text-break">{{ $official->email }}</span>
                    </td>
                    <td><x-status-badge :status="$official->status" /></td>
                    <td class="text-end">{{ $official->published_count }}</td>
                    <td class="text-end">{{ $official->waiting_count }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('admin.barangay-officials.show', $official) }}">Open</a>
                    </td>
                </tr>
            @endforeach

            {{-- Inside the panel now, not floating under it. --}}
            <x-slot:below>{{ $officials->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
