@extends('layouts.admin')

@section('title', 'Owner applications')

@section('content')
    <x-page-header title="Resort owner applications"
                   subtitle="Approve an application to let the owner sign in and submit their resort." />

    <ul class="nav nav-pills mb-3">
        @foreach ([[\App\Enums\UserStatus::Pending, 'Waiting for review'], [\App\Enums\UserStatus::Rejected, 'Turned down']] as [$tab, $label])
            <li class="nav-item">
                <a class="nav-link @if ($status === $tab) active @endif"
                   href="{{ route('admin.owner-applications.index', ['status' => $tab->value]) }}">
                    {{ $label }}
                    <span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$tab->value] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($applications->isEmpty())
        <x-empty-state icon="inbox"
                       :title="$status === \App\Enums\UserStatus::Pending ? 'No applications waiting' : 'Nothing was turned down'"
                       message="New resort owner applications appear here as soon as they are submitted." />
    @else
        <x-data-table label="Resort owner applications">
            <x-slot:head>
                <tr>
                    <th scope="col">Applicant</th>
                    <th scope="col">Business</th>
                    <th scope="col">Position</th>
                    <th scope="col">Applied</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($applications as $application)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $application->full_name }}</span>
                        <span class="small text-muted-vd">{{ $application->email }}</span>
                    </td>
                    <td>{{ $application->ownerProfile?->business_name ?? '—' }}</td>
                    <td>{{ $application->ownerProfile?->position ?? '—' }}</td>
                    <td>{{ $application->created_at->format('M j, Y') }}</td>
                    <td><x-status-badge :status="$application->status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.owner-applications.show', $application) }}">
                            Review
                        </a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $applications->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
