@extends('layouts.admin')

@section('title', 'Fee checkpoints')

@section('content')
    <x-page-header title="Fee checkpoints"
                   subtitle="Where the municipality collects its environmental fee.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.index') }}">Back to the fee</a>
        <a class="btn btn-primary" href="{{ route('admin.environmental-fee.checkpoints.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add a checkpoint
        </a>
    </x-page-header>

    @if ($checkpoints->isEmpty())
        {{-- Said deliberately: a municipality collects where an ordinance
             says, not everywhere the portal happens to know about. --}}
        <x-empty-state icon="geo-alt" title="No checkpoints yet"
                       message="Add the places named by the ordinance. Resorts and barangays are not collection points unless you make them one." />
    @else
        <x-data-table label="Environmental fee checkpoints">
            <x-slot:head>
                <tr>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Barangay</th>
                    <th scope="col">Collectors</th>
                    <th scope="col">Receipts</th>
                    <th scope="col">State</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach ($checkpoints as $checkpoint)
                <tr>
                    <td>
                        <a class="fw-semibold"
                           href="{{ route('admin.environmental-fee.checkpoints.show', $checkpoint) }}">
                            {{ $checkpoint->name }}
                        </a>
                        @if ($checkpoint->description)
                            <span class="d-block small text-muted-vd">
                                {{ Str::limit($checkpoint->description, 70) }}
                            </span>
                        @endif
                    </td>
                    <td>{{ $checkpoint->barangay }}</td>
                    <td>
                        @if ($checkpoint->collector_count === 0)
                            {{-- Worth flagging: an open gate nobody may work
                                 collects nothing, and looks fine from here. --}}
                            <span class="text-danger small">Nobody assigned</span>
                        @else
                            {{ $checkpoint->collector_count }}
                        @endif
                    </td>
                    <td>{{ $checkpoint->collection_count }}</td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $checkpoint->active ? 'active' : 'inactive' }}">
                            {{ $checkpoint->active ? 'Open' : 'Closed' }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('admin.environmental-fee.checkpoints.show', $checkpoint) }}">Manage</a>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
