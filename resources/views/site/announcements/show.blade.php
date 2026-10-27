@extends('layouts.public')

@section('title', $announcement->title)
@section('meta_description', $announcement->excerpt ?? Str::limit($announcement->body, 150))

@section('content')
    <div class="container py-5">
        <div class="row g-4 g-lg-5">
            <div class="col-lg-8">
                <nav aria-label="Breadcrumb" class="mb-3">
                    <ol class="breadcrumb small">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('announcements.index') }}">Announcements</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ Str::limit($announcement->title, 40) }}</li>
                    </ol>
                </nav>

                <article>
                    <p class="vd-card__eyebrow mb-1">
                        Posted {{ $announcement->published_at->format('M j, Y') }}
                        @if ($announcement->author) by the {{ config('visitdasol.contact.office') }} @endif
                    </p>
                    <h1 class="mb-3">{{ $announcement->title }}</h1>

                    @if (filled($announcement->image))
                        <img class="vd-gallery__main mb-4" src="{{ $announcement->image_url }}" alt="">
                    @endif

                    {{-- Plain text, escaped, with line breaks kept. --}}
                    <div class="vd-prose" style="white-space: pre-line">{{ $announcement->body }}</div>
                </article>
            </div>

            <div class="col-lg-4">
                @if ($more->isNotEmpty())
                    <aside class="vd-panel p-4">
                        <h2 class="h5 mb-3">More announcements</h2>
                        <ul class="list-unstyled mb-0">
                            @foreach ($more as $other)
                                <li class="border-top py-2">
                                    <a class="d-block" href="{{ route('announcements.show', $other) }}">{{ $other->title }}</a>
                                    <span class="small text-muted-vd">{{ $other->published_at->format('M j, Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </aside>
                @endif
            </div>
        </div>
    </div>
@endsection
