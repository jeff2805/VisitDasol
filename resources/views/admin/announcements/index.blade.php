@extends('layouts.admin')

@section('title', 'Announcements')

@section('content')
    <x-page-header title="Announcements" subtitle="Advisories and updates visitors see on the home page and the announcements page.">
        <a class="btn btn-primary" href="{{ route('admin.announcements.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Write an announcement
        </a>
    </x-page-header>

    <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <ul class="nav nav-pills gap-1 mb-0">
            <li class="nav-item">
                <a class="nav-link @if (! $status) active @endif" href="{{ route('admin.announcements.index') }}">All</a>
            </li>
            @foreach (\App\Enums\AnnouncementStatus::cases() as $case)
                <li class="nav-item">
                    <a class="nav-link @if ($status === $case) active @endif"
                       href="{{ route('admin.announcements.index', ['status' => $case->value]) }}">
                        {{ $case->label() }}
                        <span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$case->value] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <form method="GET" action="{{ route('admin.announcements.index') }}" class="d-flex gap-2 ms-auto">
            <input type="search" class="form-control" name="q" value="{{ $search }}" placeholder="Search titles" aria-label="Search announcements">
            <button type="submit" class="btn btn-light border">Search</button>
        </form>
    </div>

    @if ($announcements->isEmpty())
        <x-empty-state icon="megaphone" title="Nothing written yet"
                       message="Post advisories, schedules, and tourism updates here. Drafts stay private until you publish them.">
            <a class="btn btn-primary" href="{{ route('admin.announcements.create') }}">Write the first one</a>
        </x-empty-state>
    @else
        <x-data-table label="Announcements">
            <x-slot:head>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Status</th>
                    <th scope="col">Published</th>
                    <th scope="col">Written by</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </x-slot:head>

                        @foreach ($announcements as $announcement)
                            <tr>
                                <td>
                                    <span class="d-block fw-semibold">{{ $announcement->title }}</span>
                                    @if ($announcement->is_featured)
                                        <span class="small text-muted-vd"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i> Pinned</span>
                                    @endif
                                </td>
                                <td>
                                    <x-status-badge :status="$announcement->status" />
                                    @if ($announcement->status === \App\Enums\AnnouncementStatus::Published && ! $announcement->isPublished())
                                        <span class="d-block small text-muted-vd">Scheduled</span>
                                    @endif
                                </td>
                                <td>{{ $announcement->published_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                <td>{{ $announcement->author?->full_name ?? '—' }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <a class="btn btn-sm btn-primary" href="{{ route('admin.announcements.edit', $announcement) }}">Edit</a>

                                        @can('publish', $announcement)
                                            <form method="POST" action="{{ route('admin.announcements.publish', $announcement) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border">Publish</button>
                                            </form>
                                        @endcan

                                        @can('unpublish', $announcement)
                                            <form method="POST" action="{{ route('admin.announcements.unpublish', $announcement) }}"
                                                  data-confirm="Take this down? Visitors will no longer see it.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border">Take down</button>
                                            </form>
                                        @endcan

                                        @can('feature', $announcement)
                                            <form method="POST" action="{{ route('admin.announcements.feature', $announcement) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border"
                                                        aria-label="{{ $announcement->is_featured ? 'Unpin' : 'Pin to the top' }}">
                                                    <i class="bi bi-pin-angle{{ $announcement->is_featured ? '-fill' : '' }}" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endcan

                                        <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}"
                                              data-confirm="Delete {{ $announcement->title }}? This cannot be undone.">
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

            <x-slot:below>{{ $announcements->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
