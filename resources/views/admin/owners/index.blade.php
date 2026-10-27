@extends('layouts.admin')

@section('title', 'Resort owners')

@section('content')
    <x-page-header title="Resort owners" subtitle="Owner accounts and the listing each one manages." />

    <form method="GET" action="{{ route('admin.owners.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $search }}"
                       placeholder="Name, email, business, or resort">
            </div>

            {{-- The status pills below post through this form too, so a search
                 and a status narrow the list together instead of replacing
                 each other. --}}
            @if ($status)
                <input type="hidden" name="status" value="{{ $status->value }}">
            @endif

            <div class="col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                @if ($search !== '' || $status || ! $sort->isDefault())
                    <a class="btn btn-light border" href="{{ route('admin.owners.index') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <a class="nav-link @if (! $status) active @endif"
               href="{{ route('admin.owners.index', ['q' => $search ?: null]) }}">All</a>
        </li>
        @foreach (\App\Enums\UserStatus::options() as $value => $label)
            <li class="nav-item">
                <a class="nav-link @if ($status?->value === $value) active @endif"
                   href="{{ route('admin.owners.index', ['status' => $value, 'q' => $search ?: null]) }}">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    @if ($owners->isEmpty())
        <x-empty-state icon="person-badge"
                       :title="$search !== '' || $status ? 'No owners match' : 'No resort owners yet'"
                       :message="$search !== '' || $status
                           ? 'Try a different search, or clear the filters.'
                           : 'Approved applications appear here with the resort they manage.'">
            @if ($search !== '' || $status)
                <a class="btn btn-primary" href="{{ route('admin.owners.index') }}">Clear filters</a>
            @endif
        </x-empty-state>
    @else
        <x-data-table label="Resort owners">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="name">Owner</x-sort-header>
                    {{-- Business and resort names live on other tables, reached
                         by eager loading rather than a join, so there is no
                         column on this query to order them by. --}}
                    <th scope="col">Business</th>
                    <x-sort-header :sort="$sort" column="status">Account</x-sort-header>
                    <th scope="col">Resort</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

                        @foreach ($owners as $owner)
                            <tr>
                                <td>
                                    <span class="d-block fw-semibold">{{ $owner->full_name }}</span>
                                    <span class="small text-muted-vd">{{ $owner->email }}</span>
                                </td>
                                <td>{{ $owner->ownerProfile?->business_name ?? '—' }}</td>
                                <td><x-status-badge :status="$owner->status" /></td>
                                <td>
                                    @if ($owner->resort)
                                        <span class="d-block">{{ $owner->resort->name }}</span>
                                        <x-status-badge :status="$owner->resort->status" />
                                    @else
                                        <span class="text-muted-vd">None yet</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $owner) }}">View</a>
                                </td>
                            </tr>
                        @endforeach

            <x-slot:below>{{ $owners->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
