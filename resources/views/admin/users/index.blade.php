@extends('layouts.admin')

@section('title', 'Users')

@section('content')
    <x-page-header title="Accounts" subtitle="Everyone registered on Visit Dasol." />

    <form method="GET" action="{{ route('admin.users.index') }}" class="vd-panel p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $search }}" placeholder="Name or email">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-role">Account type</label>
                <select class="form-select" id="filter-role" name="role">
                    <option value="">All types</option>
                    @foreach (\App\Enums\UserRole::options() as $value => $label)
                        <option value="{{ $value }}" @selected($role?->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-status">Status</label>
                <select class="form-select" id="filter-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\UserStatus::options() as $value => $label)
                        <option value="{{ $value }}" @selected($status?->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                {{-- Also once a column has been sorted, so there is a way back
                     to the list's own order. --}}
                @if ($search !== '' || $role || $status || ! $sort->isDefault())
                    <a class="btn btn-light border" href="{{ route('admin.users.index') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    @if ($users->isEmpty())
        <x-empty-state icon="people" title="No accounts match" message="Try a different search or clear the filters." />
    @else
        <x-data-table label="Accounts">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="name">Name</x-sort-header>
                    <x-sort-header :sort="$sort" column="role">Account type</x-sort-header>
                    <x-sort-header :sort="$sort" column="status">Status</x-sort-header>
                    <x-sort-header :sort="$sort" column="registered">Registered</x-sort-header>
                    <x-sort-header :sort="$sort" column="signed_in">Last sign-in</x-sort-header>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($users as $person)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $person->full_name }}</span>
                        <span class="small text-muted-vd">{{ $person->email }}</span>
                    </td>
                    <td>{{ $person->role->label() }}</td>
                    <td><x-status-badge :status="$person->status" /></td>
                    <td>{{ $person->created_at->format('M j, Y') }}</td>
                    <td>{{ $person->last_login_at?->format('M j, Y') ?? 'Never' }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $person) }}">View</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $users->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
