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

                {{-- The three promotional lines that stood here — Explore
                     Destinations, Find Accommodations, Experience the Beauty
                     of Dasol — have been taken out. The panel is the
                     photograph, the wording above, and the office line at the
                     foot; nothing here sells the site to somebody who has
                     already come to sign in to it.

                     The spacing needs no replacement. .vd-auth__office carries
                     margin-top: auto, so the foot of the panel is held down by
                     the flex column rather than by the height of whatever sat
                     above it. --}}
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
