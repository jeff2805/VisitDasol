@extends('layouts.admin')

@section('title', 'Resorts')

@section('content')
    <x-page-header title="All resorts" subtitle="Every listing, whatever its status." />

    <form method="GET" action="{{ route('admin.resorts.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $search }}" placeholder="Resort or owner">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-status">Status</label>
                <select class="form-select" id="filter-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\ResortStatus::options() as $value => $label)
                        <option value="{{ $value }}" @selected($status?->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-barangay">Barangay</label>
                <select class="form-select" id="filter-barangay" name="barangay">
                    <option value="">All barangays</option>
                    @foreach ($barangays as $item)
                        <option value="{{ $item }}" @selected($barangay === $item)>{{ $item }}</option>
                    @endforeach
                </select>
            </div>
            {{-- Clear also appears once a column has been sorted, or there
                 would be no way back to the office's own order: featured
                 resorts first, then alphabetical. --}}
            <div class="col-md-2 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                @if ($search !== '' || $status || $barangay || ! $sort->isDefault())
                    <a class="btn btn-light border" href="{{ route('admin.resorts.index') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($resorts->isEmpty())
        <x-empty-state icon="buildings" title="No resorts match" message="Try a different search or clear the filters." />
    @else
        <x-data-table label="Resorts">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="name">Resort</x-sort-header>
                    {{-- Owner is not sortable: the name lives on another table
                         and this query reaches it by eager loading, not a join,
                         so there is nothing here to order by. --}}
                    <th scope="col">Owner</th>
                    <x-sort-header :sort="$sort" column="barangay">Barangay</x-sort-header>
                    <x-sort-header :sort="$sort" column="rooms">Rooms</x-sort-header>
                    <x-sort-header :sort="$sort" column="status">Status</x-sort-header>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($resorts as $resort)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $resort->name }}</span>
                        @if ($resort->is_featured)
                            <span class="small text-muted-vd"><i class="bi bi-star-fill" aria-hidden="true"></i> Featured</span>
                        @endif
                    </td>
                    <td>
                        <span class="d-block">{{ $resort->owner?->full_name }}</span>
                        <span class="small text-muted-vd">{{ $resort->owner?->status->label() }}</span>
                    </td>
                    <td>{{ $resort->barangay }}</td>
                    <td>{{ $resort->rooms_count }}</td>
                    <td>
                        <x-status-badge :status="$resort->status" />
                        @unless ($resort->isPubliclyVisible())
                            <span class="d-block small text-muted-vd">Not public</span>
                        @endunless
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.resorts.show', $resort) }}">View</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $resorts->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
