@extends('layouts.admin')

@section('title', 'Destination submissions')

@section('content')
    <x-page-header title="Destination submissions"
                   subtitle="Sent in by the barangays. None of these is visible to visitors yet." />

    @if ($submissions->isEmpty())
        <div class="vd-panel p-5 text-center">
            <p class="h5 mb-2">Nothing waiting</p>
            <p class="text-muted-vd mb-0">
                When a barangay official sends in a destination, it appears here for you to read.
            </p>
        </div>
    @else
        <x-data-table label="Destination submissions">
            <x-slot:head>
                <tr>
                    <th scope="col">Place</th>
                    <th scope="col">Barangay</th>
                    <th scope="col">Sent by</th>
                    <th scope="col">Waiting since</th>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($submissions as $spot)
                <tr>
                    <th scope="row" class="fw-semibold">
                        {{ $spot->name }}
                        <span class="d-block small text-muted-vd fw-normal">
                            {{ $spot->category?->name ?? '—' }}
                            @if ($spot->images_count === 0 && blank($spot->main_image))
                                &middot; <span class="text-warning-emphasis">no photo</span>
                            @endif
                        </span>
                    </th>
                    <td>{{ $spot->barangay }}</td>
                    <td>{{ $spot->creator?->full_name ?? '—' }}</td>
                    <td>{{ $spot->submitted_at?->diffForHumans() ?? '—' }}</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-primary"
                           href="{{ route('admin.spot-submissions.show', $spot) }}">Read it</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $submissions->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
