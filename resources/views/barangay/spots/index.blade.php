@extends('layouts.barangay')

@section('title', 'Destinations')

@section('content')
    <x-page-header title="Destinations" :subtitle="'Everything you have written up for Barangay '.$barangay">
        <a class="btn btn-primary" href="{{ route('barangay.spots.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a spot
        </a>
    </x-page-header>

    @if ($spots->isEmpty())
        <div class="vd-panel p-5 text-center">
            <p class="h5 mb-2">Nothing here yet</p>
            <p class="text-muted-vd mb-4">
                You know Barangay {{ $barangay }} better than anyone at the municipal hall does.
                Start with the place you would take a visitor first.
            </p>
            <a class="btn btn-primary btn-lg" href="{{ route('barangay.spots.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add the first one
            </a>
        </div>
    @else
        <x-data-table label="Your destinations">
            <x-slot:head>
                <tr>
                    <th scope="col">Place</th>
                    <th scope="col">Kind</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </x-slot:head>

                        @foreach ($spots as $spot)
                            <tr>
                                <th scope="row" class="fw-semibold">
                                    {{ $spot->name }}
                                    @if ($spot->images_count > 0 || filled($spot->main_image))
                                        <span class="d-block small text-muted-vd fw-normal">
                                            <i class="bi bi-image" aria-hidden="true"></i> has a photo
                                        </span>
                                    @else
                                        <span class="d-block small text-warning-emphasis fw-normal">
                                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> no photo yet
                                        </span>
                                    @endif
                                </th>
                                <td>{{ $spot->category?->name ?? '—' }}</td>
                                <td>
                                    <x-status-badge :status="$spot->status" />
                                    @if ($spot->status === \App\Enums\DestinationStatus::Rejected && filled($spot->review_remarks))
                                        <span class="d-block small text-muted-vd mt-1">
                                            {{ \Illuminate\Support\Str::limit($spot->review_remarks, 70) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @can('update', $spot)
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="{{ route('barangay.spots.edit', $spot) }}">Edit</a>
                                    @endcan
                                    @if ($spot->status === \App\Enums\DestinationStatus::Active)
                                        <x-external-link class="btn btn-sm btn-light border"
                                           :href="route('destinations.show', $spot)">
                                            View on the site
                                        </x-external-link>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

            <x-slot:below>{{ $spots->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
