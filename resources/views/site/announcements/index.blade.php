@extends('layouts.public')

@section('title', 'Announcements')
@section('meta_description', 'Advisories and updates from the Municipal Tourism Office of Dasol.')

@section('content')
    <div class="container py-5">
        <x-page-header title="Announcements" subtitle="Updates and advisories from the Municipal Tourism Office." />

        @if ($announcements->isEmpty())
            <x-empty-state icon="megaphone" title="No announcements yet"
                           message="Updates from the tourism office will appear here." />
        @else
            <div class="row g-4">
                @foreach ($announcements as $announcement)
                    <div class="col-md-6">
                        <article class="vd-panel p-4 h-100 vd-announcement">
                            <p class="vd-card__eyebrow mb-1">{{ $announcement->published_at->format('M j, Y') }}</p>
                            <h2 class="h5 mb-2">
                                <a class="text-decoration-none" href="{{ route('announcements.show', $announcement) }}">{{ $announcement->title }}</a>
                            </h2>
                            <p class="text-muted-vd mb-0">{{ $announcement->excerpt ?? Str::limit($announcement->body, 160) }}</p>
                        </article>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">{{ $announcements->links() }}</div>
        @endif
    </div>
@endsection
