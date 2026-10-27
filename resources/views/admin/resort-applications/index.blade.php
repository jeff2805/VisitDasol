@extends('layouts.admin')

@section('title', 'Resort applications')

@section('content')
    <x-page-header title="Resort listings for review"
                   subtitle="A listing becomes public only after you approve it." />

    <ul class="nav nav-pills mb-3">
        @foreach ([[\App\Enums\ResortStatus::Pending, 'Waiting for review'], [\App\Enums\ResortStatus::Rejected, 'Sent back']] as [$tab, $label])
            <li class="nav-item">
                <a class="nav-link @if ($status === $tab) active @endif"
                   href="{{ route('admin.resort-applications.index', ['status' => $tab->value]) }}">
                    {{ $label }}
                    <span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$tab->value] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($applications->isEmpty())
        <x-empty-state icon="building-check"
                       :title="$status === \App\Enums\ResortStatus::Pending ? 'Nothing waiting for review' : 'No listings were sent back'"
                       message="Resorts submitted by approved owners appear here." />
    @else
        <x-data-table label="Resort applications">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="name">Resort</x-sort-header>
                    {{-- The owner's name is on another table, reached by eager
                         loading rather than a join. --}}
                    <th scope="col">Owner</th>
                    <x-sort-header :sort="$sort" column="barangay">Barangay</x-sort-header>
                    <x-sort-header :sort="$sort" column="rooms">Rooms</x-sort-header>
                    <x-sort-header :sort="$sort" column="waiting">Submitted</x-sort-header>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($applications as $application)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $application->name }}</span>
                        <span class="small text-muted-vd">
                            {{ filled($application->main_image) ? 'Cover photo added' : 'No cover photo yet' }}
                        </span>
                    </td>
                    <td>
                        <span class="d-block">{{ $application->owner?->full_name }}</span>
                        <span class="small text-muted-vd">{{ $application->owner?->ownerProfile?->business_name }}</span>
                    </td>
                    <td>{{ $application->barangay }}</td>
                    <td>{{ $application->rooms_count }}</td>
                    <td>{{ $application->submitted_at?->format('M j, Y') ?? '—' }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.resort-applications.show', $application) }}">Review</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $applications->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
