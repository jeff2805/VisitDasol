@extends('layouts.owner')

@section('title', $announcement->title)

@section('content')
    <x-page-header :title="$announcement->title"
                   :subtitle="'Municipal Tourism Office · '.$announcement->published_at->format('F j, Y')">
        <a class="btn btn-light border" href="{{ route('owner.announcements.index') }}">
            Back to announcements
        </a>
    </x-page-header>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <article class="vd-panel p-4">
                @if (filled($announcement->excerpt))
                    <p class="lead">{{ $announcement->excerpt }}</p>
                @endif

                {{-- The body is plain text written in the office's own editor,
                     so its newlines are what separate its paragraphs. CSS
                     keeps them, rather than nl2br and unescaped output: the
                     office types what it likes into that box and no template
                     in this project prints anything unescaped — there is a
                     test that says so. The public page does the same. --}}
                <div class="vd-prose" style="white-space: pre-line">{{ $announcement->body }}</div>
            </article>
        </div>

        @if ($more->isNotEmpty())
            <div class="col-12 col-lg-4">
                <section class="vd-panel p-4" aria-labelledby="more-title">
                    <h2 class="h6 mb-3" id="more-title">More from the office</h2>

                    <ul class="list-unstyled mb-0">
                        @foreach ($more as $other)
                            <li class="border-top py-2">
                                <a class="d-block"
                                   href="{{ route('owner.announcements.show', $other) }}">
                                    {{ $other->title }}
                                </a>
                                <span class="small text-muted-vd">
                                    {{ $other->published_at->format('M j, Y') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        @endif
    </div>
@endsection
