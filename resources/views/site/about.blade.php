@extends('layouts.public')

@section('title', 'About Dasol')
@section('meta_description', 'About Visit Dasol, the tourism and reservation portal of the Municipality of Dasol, Pangasinan.')

@push('styles')
    <link rel="stylesheet" href="@vdasset('css/about.css')">
@endpush

@php
    /*
     * EVERY EDITABLE WORD AND PICTURE ON THIS PAGE COMES FROM THE DATABASE,
     * through AboutPageService. Nothing the tourism office may change is
     * written in this file.
     *
     * What IS written here, and deliberately stays here: how this site
     * works, why listings are reviewed, and the address the resort owner
     * button goes to. Those describe the software, and an office able to
     * edit them could publish a promise the system does not keep — or
     * quietly break resort registration with a mistyped URL.
     *
     * Nothing is printed unescaped. An office typing an angle bracket gets
     * an angle bracket, not markup.
     */
    $isPreview = $isPreview ?? false;

    $hero = $about['hero'];
    $municipality = $about['municipality'];
    $history = $about['history'];
    $heritage = $about['heritage'];
    $places = $about['destinations'];
    $location = $about['location'];
    $cta = $about['owner_cta'];

    $imageUrl = fn (?string $path): ?string =>
        filled($path) ? Storage::disk('public')->url($path) : null;

    $paragraphs = fn (?string $text): array => preg_split(
        '/\n\s*\n/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY
    ) ?: [];

    $heroImage = $imageUrl($hero['image_path'] ?? null);
@endphp

@section('content')
    <div class="vd-about">

        @if ($isPreview)
            {{-- Only the office ever sees this, from About Page Management.
                 Said plainly, because a preview that looked exactly like the
                 live page is a preview somebody mistakes for one. --}}
            <p class="vd-about__preview-bar" role="status">
                <i class="bi bi-eye" aria-hidden="true"></i>
                Preview — this shows your drafts. Visitors still see the published page.
            </p>
        @endif

        {{-- HERO ------------------------------------------------------- --}}
        <header class="vd-about__hero"
                @if ($heroImage) style="background-image:url('{{ $heroImage }}')" @endif>
            <p class="vd-about__eyebrow">{{ $hero['label'] }}</p>
            <h1 class="vd-about__title">{{ $hero['heading'] }}</h1>

            @if (filled($hero['subheading']))
                <p class="vd-about__lede">{{ $hero['subheading'] }}</p>
            @endif

            <div class="vd-about__brush" aria-hidden="true"></div>
        </header>

        <div class="vd-about__body">

            {{-- LEFT COLUMN ------------------------------------------- --}}
            <div class="vd-about__main">

                {{-- The municipality --}}
                <section class="vd-about__card" aria-labelledby="municipality-title">
                    <div class="vd-about__split">
                        @if ($url = $imageUrl($municipality['image_path'] ?? null))
                            <figure class="vd-about__figure">
                                <img src="{{ $url }}" alt="{{ $municipality['heading'] }}" loading="lazy">
                            </figure>
                        @endif

                        <div>
                            <h2 class="vd-about__heading" id="municipality-title">
                                <i class="bi bi-water" aria-hidden="true"></i>
                                <span>{{ $municipality['heading'] }}</span>
                            </h2>

                            @foreach ($paragraphs($municipality['body']) as $paragraph)
                                <p class="vd-about__prose">{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- History --}}
                <section class="vd-about__card vd-about__card--sand" aria-labelledby="history-title">
                    <div class="vd-about__split">
                        @if ($url = $imageUrl($history['image_path'] ?? null))
                            <figure class="vd-about__figure">
                                <img src="{{ $url }}" alt="{{ $history['heading'] }}" loading="lazy">
                            </figure>
                        @endif

                        <div>
                            <h2 class="vd-about__heading" id="history-title">
                                <i class="bi bi-book" aria-hidden="true"></i>
                                <span>{{ $history['heading'] }}</span>
                            </h2>

                            {{-- An ordered list, so the office can move a
                                 paragraph without cutting a sentence out of
                                 the middle of a box. --}}
                            @forelse ($history['paragraphs'] as $paragraph)
                                <p class="vd-about__prose">{{ $paragraph }}</p>
                            @empty
                                <p class="vd-about__empty">No history has been written yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                {{-- Salt-making heritage --}}
                <section class="vd-about__card vd-about__card--sand" aria-labelledby="heritage-title">
                    <div class="vd-about__split vd-about__split--text-first">
                        <div>
                            <h2 class="vd-about__heading" id="heritage-title">
                                <i class="bi bi-moisture" aria-hidden="true"></i>
                                <span>{{ $heritage['heading'] }}</span>
                            </h2>

                            @foreach ($paragraphs($heritage['body']) as $paragraph)
                                <p class="vd-about__prose">{{ $paragraph }}</p>
                            @endforeach
                        </div>

                        @if (filled($heritage['images'] ?? []))
                            <div class="vd-about__gallery">
                                @foreach ($heritage['images'] as $image)
                                    <figure>
                                        <img src="{{ $imageUrl($image['path']) }}"
                                             alt="{{ $image['caption'] ?? $heritage['heading'] }}"
                                             loading="lazy">
                                        @if (filled($image['caption']))
                                            <figcaption>{{ $image['caption'] }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Destinations. The records themselves come from the
                     Destinations module; this page chooses which and in
                     what order, and never describes one of its own. --}}
                <section class="vd-about__card" aria-labelledby="places-title">
                    <h2 class="vd-about__heading" id="places-title">
                        <i class="bi bi-camera" aria-hidden="true"></i>
                        <span>{{ $places['heading'] }}</span>
                    </h2>

                    @if (filled($places['subheading']))
                        <p class="vd-about__prose">{{ $places['subheading'] }}</p>
                    @endif

                    @if ($destinations->isNotEmpty())
                        <ul class="vd-about__destinations">
                            @foreach ($destinations as $destination)
                                <li class="vd-about__destination">
                                    <img src="{{ $destination->main_image_url }}"
                                         alt="{{ $destination->name }}" loading="lazy">

                                    <a class="vd-about__destination-name"
                                       href="{{ route('destinations.show', $destination) }}">
                                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                                        {{ $destination->name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="vd-about__empty">No destinations have been chosen yet.</p>
                    @endif
                </section>

                {{-- HOW THIS SITE WORKS and WHY LISTINGS ARE REVIEWED stay
                     in this template on purpose. They describe what the
                     software does, not what Dasol is. --}}
                <section class="vd-about__card" aria-labelledby="how-title">
                    <h2 class="vd-about__heading" id="how-title">
                        <i class="bi bi-compass" aria-hidden="true"></i>
                        <span>How this site works</span>
                    </h2>

                    <p class="vd-about__prose">
                        Visit Dasol brings the municipality's resorts into one place. Visitors
                        search by date and party size, send a reservation request, and deal with
                        the resort directly. Resort owners list their rooms and answer requests
                        from their own dashboard. The tourism office reviews listings and keeps
                        the municipality's visitor statistics.
                    </p>

                    <p class="vd-about__prose">
                        Every resort on this site has been checked by the Municipal Tourism Office
                        before it appears. A listing is read for a real address, real rooms and a
                        contactable owner, so a visitor booking here is booking somewhere the
                        office knows exists.
                    </p>
                </section>
            </div>

            {{-- RIGHT COLUMN ------------------------------------------ --}}
            <aside class="vd-about__aside">

                <section class="vd-about__panel" aria-labelledby="location-title">
                    <h2 id="location-title">
                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                        {{ $location['heading'] }}
                    </h2>

                    <p>{{ $location['municipality'] }}, {{ $location['province'] }}, {{ $location['country'] }}</p>

                    <dl>
                        <dt>Region</dt>
                        <dd>{{ $location['region'] }}</dd>
                        <dt>Postal code</dt>
                        <dd>{{ $location['postal_code'] }}</dd>
                    </dl>
                </section>

                @if ($cta['visible'])
                    <section class="vd-about__panel" aria-labelledby="owner-title">
                        <h2 id="owner-title">
                            <i class="bi bi-house-door-fill" aria-hidden="true"></i>
                            {{ $cta['heading'] }}
                        </h2>

                        <p>{{ $cta['body'] }}</p>

                        {{-- The address is the application's, not the
                             office's: a mistyped URL here would break resort
                             registration for everybody, so it is not
                             editable anywhere. --}}
                        <a class="vd-about__cta" href="{{ route('register.owner') }}">
                            {{ $cta['button_label'] }}
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    </section>
                @endif

                <section class="vd-about__panel" aria-labelledby="office-title">
                    @php($contact = config('visitdasol.contact'))

                    <h2 id="office-title">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        {{ $contact['office'] }}
                    </h2>

                    @if (filled($contact['address'] ?? null))
                        <p>{{ $contact['address'] }}</p>
                    @endif

                    <dl>
                        @if (filled($contact['email'] ?? null))
                            <dt>Email</dt>
                            <dd>{{ $contact['email'] }}</dd>
                        @endif
                        @if (filled($contact['phone'] ?? null))
                            <dt>Phone</dt>
                            <dd>{{ $contact['phone'] }}</dd>
                        @endif
                    </dl>
                </section>
            </aside>
        </div>
    </div>
@endsection
