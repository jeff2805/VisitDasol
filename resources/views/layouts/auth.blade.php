{{--
    Sign-in and registration layout: a Dasol Bay panel beside the form.
    Pages use:
      @section('panel_title')  short heading in the panel (optional)
      @section('panel_text')   one or two sentences in the panel (optional)
      @section('content')      the form card
--}}
@extends('layouts.base')

@section('body')
    <div class="row g-0 vd-auth">
        <aside class="col-lg-6 vd-auth__panel">
            <div class="vd-saltbeds vd-saltbeds--full" aria-hidden="true"></div>

            <div class="position-relative">
                <x-brand :dark="true" />

                {{-- The site's own tagline, set as the lockup under the mark.
                     It is not part of the logo artwork, so it is typed here
                     rather than drawn into the image — which also means it can
                     be read, translated and searched.

                     The words come from config and the separators are drawn
                     here: "Explore. Discover. Experience." is a sentence on the
                     home page and a spaced lockup on this panel, and the
                     stylesheet cannot turn full stops into bullets. --}}
                @php
                    $taglineWords = collect(explode('.', config('visitdasol.tagline')))
                        ->map(fn (string $word) => trim($word))
                        ->filter()
                        ->all();
                @endphp

                <p class="vd-auth__tagline">
                    @foreach ($taglineWords as $word)
                        <span>{{ $word }}</span>
                    @endforeach
                </p>
            </div>

            <div class="position-relative d-none d-lg-block">
                {{-- A tagline, not the page's heading.

                     It was an <h1>, which left every sign-in and registration
                     page with two of them — and the one that mattered ("Sign
                     in") was the second. It is also hidden below large
                     screens, so on a phone the page's only h1 was decoration
                     that nobody could see. --}}
                <p class="vd-auth__panel-title">@yield('panel_title', 'Explore. Discover. Experience.')</p>
                <p class="vd-auth__panel-text mb-0 mt-3">@yield('panel_text', 'Book resorts in Dasol, Pangasinan, and find places to visit along the bay.')</p>

                {{-- What is on the other side of signing in. The panel carried
                     a photograph and a sentence and then a lot of empty navy;
                     these three say what the site is for, which is what a
                     sign-in page is otherwise silent about.

                     The icons repeat the words rather than replacing them, so
                     they are hidden from assistive technology. --}}
                {{-- The line breaks matter: the strong is a block, so the two
                     halves sit on separate lines either way, but written
                     without a space between the tags they are read aloud as
                     one word — "Experiencethe Beauty of Dasol". --}}
                <ul class="vd-auth__features">
                    <li>
                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                        <span>
                            <strong>Explore</strong>
                            Destinations
                        </span>
                    </li>
                    <li>
                        <i class="bi bi-house-heart-fill" aria-hidden="true"></i>
                        <span>
                            <strong>Find</strong>
                            Accommodations
                        </span>
                    </li>
                    <li>
                        <i class="bi bi-camera-fill" aria-hidden="true"></i>
                        <span>
                            <strong>Experience</strong>
                            the Beauty of Dasol
                        </span>
                    </li>
                </ul>
            </div>

            <p class="position-relative small mb-0 d-none d-lg-block text-white-50 vd-auth__office">
                {{ config('visitdasol.contact.office') }}, {{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}
            </p>

            {{-- Two curves, the back one offset and dimmed, so the foot of the
                 panel reads as water rather than as a single drawn line. --}}
            <x-shore-curve class="vd-auth__wave vd-auth__wave--back" />
            <x-shore-curve class="vd-auth__wave" />
        </aside>

        <main id="main-content" class="col-lg-6 vd-auth__form-wrap" tabindex="-1">
            {{-- The same fronds that stand behind the home page's sections, so
                 the form does not sit on a bare white rectangle. Decorative,
                 and hidden below large screens by its own stylesheet. --}}
            <x-palm-fronds side="right" />

            {{-- The same water, carried across the foot of this side at a
                 fraction of the strength. --}}
            <x-shore-curve class="vd-auth__wave vd-auth__wave--pale" />

            <div class="vd-auth__card">
                @include('partials.flash')

                @yield('content')

                <p class="text-center mt-4 mb-0">
                    <a href="{{ route('home') }}" class="text-decoration-none">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Visit Dasol
                    </a>
                </p>
            </div>
        </main>
    </div>
@endsection
