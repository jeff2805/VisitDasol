@extends('layouts.admin')

@section('title', 'Destinations')

@section('content')
    <x-page-header title="Destinations" subtitle="Beaches, viewpoints, and other places visitors can look up.">
        <a class="btn btn-primary" href="{{ route('admin.destinations.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add destination
        </a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.destinations.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $search }}" placeholder="Name or barangay">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-category">Category</label>
                <select class="form-select" id="filter-category" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $item)
                        <option value="{{ $item->slug }}" @selected($category?->id === $item->id)>{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-status">Status</label>
                <select class="form-select" id="filter-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\DestinationStatus::options() as $value => $label)
                        <option value="{{ $value }}" @selected($status?->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                @if ($search !== '' || $status || $category || ! $sort->isDefault())
                    <a class="btn btn-light border" href="{{ route('admin.destinations.index') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($destinations->isEmpty())
        <x-empty-state icon="signpost-2" title="No destinations yet"
                       message="Add the places the tourism office wants visitors to know about.">
            <a class="btn btn-primary" href="{{ route('admin.destinations.create') }}">Add the first destination</a>
        </x-empty-state>
    @else
        <x-data-table label="Destinations">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="name">Destination</x-sort-header>
                    {{-- Category is on another table, reached by eager loading. --}}
                    <th scope="col">Category</th>
                    <x-sort-header :sort="$sort" column="barangay">Barangay</x-sort-header>
                    <x-sort-header :sort="$sort" column="photos">Photos</x-sort-header>
                    <x-sort-header :sort="$sort" column="status">Status</x-sort-header>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </x-slot:head>

                        @foreach ($destinations as $destination)
                            <tr>
                                <td>
                                    <span class="d-block fw-semibold">{{ $destination->name }}</span>
                                    @if ($destination->is_featured)
                                        <span class="small text-muted-vd"><i class="bi bi-star-fill" aria-hidden="true"></i> Featured</span>
                                    @endif
                                </td>
                                <td>{{ $destination->category?->name }}</td>
                                <td>{{ $destination->barangay ?? '—' }}</td>
                                <td>{{ $destination->images_count }}</td>
                                <td><x-status-badge :status="$destination->status" /></td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <a class="btn btn-sm btn-primary" href="{{ route('admin.destinations.edit', $destination) }}">Edit</a>

                                        <form method="POST" action="{{ route('admin.destinations.status', $destination) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-light border">
                                                {{ $destination->isActive() ? 'Hide' : 'Publish' }}
                                            </button>
                                        </form>

                                        @can('feature', $destination)
                                            <form method="POST" action="{{ route('admin.destinations.feature', $destination) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border" aria-label="Toggle featured">
                                                    <i class="bi bi-star{{ $destination->is_featured ? '-fill' : '' }}" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endcan

                                        <form method="POST" action="{{ route('admin.destinations.destroy', $destination) }}"
                                              data-confirm="Delete {{ $destination->name }}? This also removes its photos.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-light border text-danger" aria-label="Delete">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach

            <x-slot:below>{{ $destinations->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
