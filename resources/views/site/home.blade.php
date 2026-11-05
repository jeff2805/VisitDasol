{{-- Public home page: featured resorts, destinations, and the latest announcements. --}}
@extends('layouts.public')

@section('title', 'Explore. Discover. Experience.')

@section('content')
    <section class="vd-hero" aria-labelledby="hero-title">
        <div class="vd-saltbeds" aria-hidden="true"></div>

        <div class="container position-relative">
            
            <h1 class="vd-wordmark" id="hero-title">{{ config('visitdasol.name') }}</h1>
            <p class="vd-hero__tagline">{{ config('visitdasol.tagline') }}</p>
            <p class="vd-hero__lead"> Find a resort on Dasol Pangasinan, see where it is on the map, and request a room online.
                 Every listing is reviewed by the {{ config('visitdasol.contact.office') }}.
            </p>

            {{-- Plan your trip ------------------------------------------------
                 Inside the hero, under the description and above the card, so
                 the empty stretch of beach between the wording and the curve
                 does something. The two buttons go to screens that already
                 exist: the map carries the route planner, and the tourist-spot
                 listing is the destinations page. --}}
            <div class="vd-plan">
                <h2 class="vd-plan__title">
                    Plan Your Trip to <span class="vd-plan__accent">Dasol</span>
                </h2>

                <p class="vd-plan__text">
                    Discover beautiful beaches, explore tourist attractions, and create
                    your perfect getaway in Dasol, Pangasinan.
                </p>

                <div class="vd-plan__actions">
                    <a class="vd-plan__btn vd-plan__btn--gold" href="{{ route('map') }}">
                        <i class="bi bi-map-fill" aria-hidden="true"></i>
                        Plan My Trip
                    </a>

                    <a class="vd-plan__btn vd-plan__btn--bay" href="{{ route('destinations.index') }}">
                        <i class="bi bi-compass-fill" aria-hidden="true"></i>
                        Explore Destinations
                    </a>
                </div>
            </div>

            {{-- What a visitor asks before they book: when to come, what the
                 weather does, and how to get here.

                 EVERY LINE IS GENERAL AND CHECKABLE. No departure times, no
                 fares, no journey lengths — this page cannot keep a timetable
                 current, and a stale one would strand somebody. "Explore the
                 available routes" sends them to the map, which knows. --}}
            <div class="vd-trip-facts">
                <article class="vd-trip-facts__item">
                    <span class="vd-trip-facts__icon">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                    </span>
                    <p class="vd-trip-facts__label">Best time</p>
                    <h3 class="vd-trip-facts__heading">December &ndash; May</h3>
                    <p class="vd-trip-facts__text">
                        Enjoy sunny weather and beautiful coastal conditions, perfect for
                        beach activities, island exploration, and relaxing seaside vacations.
                    </p>
                </article>

                <article class="vd-trip-facts__item">
                    <span class="vd-trip-facts__icon">
                        <i class="bi bi-cloud-sun-fill" aria-hidden="true"></i>
                    </span>
                    <p class="vd-trip-facts__label">Climate &amp; weather</p>
                    <h3 class="vd-trip-facts__heading">Tropical Coastal Climate</h3>
                    <p class="vd-trip-facts__text">
                        Dasol offers a warm tropical climate with sunny days, coastal breezes,
                        and seasonal rainfall. Check local weather conditions before travelling.
                    </p>
                </article>

                <article class="vd-trip-facts__item">
                    <span class="vd-trip-facts__icon">
                        <i class="bi bi-signpost-split-fill" aria-hidden="true"></i>
                    </span>
                    <p class="vd-trip-facts__label">How to get there</p>
                    <h3 class="vd-trip-facts__heading">Via Alaminos or Nearby Coastal Routes</h3>
                    <p class="vd-trip-facts__text">
                        Travel to Dasol, Pangasinan by road from nearby cities.
                        <a href="{{ route('map') }}">Explore the available routes</a>
                        and discover the best way to reach your destination.
                    </p>
                </article>
            </div>
        </div>

        <x-shore-curve />
    </section>

    {{-- The stay search, on its own band between the hero and the listings.

         IT USED TO SIT INSIDE THE HERO, which is a fixed 650px tall with
         overflow: hidden, and it was pushed down into that box by a
         `margin-top: 4rem !important` written for exactly that spot. On a
         short window the foot of the card met the bottom of the hero and was
         clipped by the overflow.

         Out here it is in ordinary flow directly above "Discover Dasol", the
         first thing a visitor reads after the picture and the thing they
         came to do. Same component, same four fields, same button, same
         route — nothing about the form itself changed, only where it stands.

         Top padding only: the tinted section below brings its own top
         padding, and giving this one a bottom as well would stack two gaps
         into a band of empty page. --}}
    <section class="vd-booking-bar" aria-label="Find a room">
        <div class="container">
            <x-stay-search :barangays="config('visitdasol.barangays')" />
        </div>
    </section>

    <section class="vd-section vd-section--tinted" aria-labelledby="resorts-title">
        <x-palm-fronds side="left" />
        <x-palm-fronds side="right" />

        <div class="container position-relative">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                <div>
                    <p class="vd-eyebrow">Discover Dasol</p>
                    <h2 id="resorts-title" class="mb-1">Places to stay</h2>
                    <p class="text-muted-vd mb-0">Browse featured resorts and find the perfect place for your Dasol getaway.</p>
                </div>
                <a class="btn btn-outline-primary rounded-pill px-4 d-inline-flex align-items-center gap-2" href="{{ route('resorts.index') }}">
                    View all resorts
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            @if ($resorts->isEmpty())
                <x-empty-state icon="buildings" title="No resorts listed yet"
                               message="Approved resorts appear here as soon as the tourism office publishes them." />
            @else
                <div class="row g-4">
                    @foreach ($resorts as $resort)
                        <div class="col-md-6 col-lg-4">
                            <x-resort-card :resort="$resort" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($destinations->isNotEmpty())
        <section class="vd-section pt-0" aria-labelledby="destinations-title">
            <div class="container">
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                    <div>
                        <p class="vd-eyebrow">Where to go</p>
                        <h2 id="destinations-title" class="mb-1">Places to see</h2>
                        <p class="text-muted-vd mb-0">Beaches, viewpoints, and heritage spots listed by the tourism office.</p>
                    </div>
                    <a class="btn btn-outline-primary rounded-pill px-4 d-inline-flex align-items-center gap-2" href="{{ route('destinations.index') }}">
                        View all destinations
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="row g-4">
                    @foreach ($destinations as $destination)
                        <div class="col-md-6 col-lg-4">
                            <x-destination-card :destination="$destination" />
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- A reason to open the full map, not a second copy of it. The real one —
         every listing, every barangay, satellite view, road distance — is one
         click away and stays the only place that experience lives. --}}
    @if ($mapPreview->isNotEmpty())
        <section class="vd-section pt-0" aria-labelledby="map-preview-title">
            <div class="container">
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                    <div>
                        <p class="vd-eyebrow">Find your way</p>
                        <h2 id="map-preview-title" class="mb-1">See it on the map</h2>
                        <p class="text-muted-vd mb-0">Every resort and destination above, placed on Dasol Bay.</p>
                    </div>
                    <a class="btn btn-outline-primary rounded-pill px-4 d-inline-flex align-items-center gap-2" href="{{ route('map') }}">
                        Open the full map
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </div>

                <x-visit-map :points="$mapPreview" class="vd-map--preview" />
            </div>
        </section>
    @endif

    @if ($announcements->isNotEmpty())
        <section class="vd-section pt-0" aria-labelledby="announcements-title">
            <div class="container">
                <p class="vd-eyebrow">News and advisories</p>
                <h2 id="announcements-title" class="mb-4">Latest from the tourism office</h2>

                <div class="row g-4">
                    @foreach ($announcements as $announcement)
                        <div class="col-md-6">
                            <article class="vd-panel p-4 h-100 vd-announcement">
                                <p class="vd-card__eyebrow mb-1">{{ $announcement->published_at->format('M j, Y') }}</p>
                                <h3 class="h5 mb-2">
                                    <a class="text-decoration-none" href="{{ route('announcements.show', $announcement) }}">{{ $announcement->title }}</a>
                                </h3>
                                <p class="text-muted-vd mb-0">{{ $announcement->excerpt ?? Str::limit($announcement->body, 140) }}</p>
                            </article>
                        </div>
                    @endforeach
                </div>

                <a class="btn btn-outline-primary mt-4" href="{{ route('announcements.index') }}">All announcements</a>
            </div>
        </section>
    @endif

    <section class="pb-5" aria-labelledby="owners-title">
        <div class="container">
            <div class="vd-owner-band">
                <div class="vd-saltbeds" aria-hidden="true"></div>
                <div class="row align-items-center g-3 position-relative">
                    <div class="col-lg-8">
                        <h2 id="owners-title" class="h3">Own or manage a resort in Dasol?</h2>
                        <p class="mb-0 opacity-75">
                            Apply for an owner account. After the {{ config('visitdasol.contact.office') }} approves it,
                            you can submit your resort for review and add your rooms.
                        </p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        @if (Route::has('register.owner'))
                            <a class="btn btn-accent btn-lg" href="{{ route('register.owner') }}">Apply as a resort owner</a>
                        @else
                            <span class="btn btn-accent btn-lg disabled" aria-disabled="true">Apply as a resort owner</span>
                        @endif
                    </div>
                </div>
            </div>

            @env('local')
                <div class="vd-dev-note mt-4">
                    <strong>Development build.</strong>
                    This note and the setup page are only visible while APP_ENV=local.
                    <a href="{{ route('dev.setup') }}">Open the setup check</a>
                </div>
            @endenv
        </div>
    </section>
@endsection
