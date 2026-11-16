{{-- 
    NOTE FOR THE MUNICIPAL TOURISM OFFICE
    The text below is deliberately brief and factual. Replace the
    "About the municipality" section with your own official description,
    and fill in the contact details in config/visitdasol.php.
--}}
@extends('layouts.public')

@section('title', 'About Dasol')
@section('meta_description', 'About Visit Dasol, the tourism and reservation portal of the Municipality of Dasol, Pangasinan.')

@section('content')
    <div class="container py-5">
        <div class="row g-4 g-lg-5">
            <div class="col-lg-8">

                <x-page-header title="About Visit Dasol"
                    subtitle="Tourism information and resort reservations for Dasol, Pangasinan." />

                {{-- THE MUNICIPALITY --}}
                <section class="mb-5" aria-labelledby="municipality-title">
                    <h2 id="municipality-title" class="h4">The municipality</h2>

                    <p class="vd-prose">
                        Dasol is a coastal municipality in the western part of Pangasinan, in the Ilocos Region
                        of the Philippines. It is made up of 18 barangays and uses the postal code
                        {{ config('visitdasol.postal_code') }}. The town is long associated with salt-making,
                        and its shoreline faces Dasol.
                    </p>
                </section>

                {{-- HISTORY OF DASOL --}}
                <section class="mb-5" aria-labelledby="history-title">
                    <h2 id="history-title" class="h4 mb-3">History of Dasol</h2>

                    <p class="vd-prose">
                        Dasol is a serene coastal municipality located in the western part of Pangasinan,
                        known for its natural beauty and rich cultural heritage. It is recognized as one
                        of Pangasinan’s hidden gems and is also known as the “Home of Quality Salt.”
                    </p>

                    <p class="vd-prose">
                        The name Dasol originated from the medicinal herb called <em>dosol</em>, which
                        reportedly grew abundantly in the area during the Spanish period. The plant was
                        traditionally used to treat infected wounds. Its name was frequently mispronounced
                        by the Spaniards as “Dasol,” eventually becoming the municipality’s official name.
                        The Ilocano word <em>dasol</em>, meaning “to stretch,” is also associated with the
                        long stretch of white-sand beaches along its coastline.
                    </p>

                    <p class="vd-prose">
                        Originally part of the municipality of San Isidro de Potot, now known as Burgos,
                        Dasol became a separate municipality during the American colonial period. It
                        officially became an independent municipality on January 27, 1911. Its early
                        settlers were primarily Ilocanos and Pangasinenses who relied on farming, fishing,
                        and salt-making for their livelihood.
                    </p>

                    <p class="vd-prose">
                        During World War II, Dasol became historically significant as an area of underground
                        guerrilla resistance against Japanese occupation. Its caves and rugged terrain served
                        as shelters and meeting places for freedom fighters. The local salt industry also
                        played an important role in sustaining communities during wartime shortages.
                    </p>

                    <p class="vd-prose">
                        Today, Dasol is recognized for its beautiful coastal destinations, including
                        Tambobong Beach and Balinmanok, crystal-clear waters, and hidden coves. It continues
                        to develop as an emerging ecotourism destination while preserving its traditions,
                        cultural identity, and resilient community.
                    </p>

                    <p class="vd-prose">
                        Whether visitors come to enjoy its tranquil beaches, explore its history, discover
                        its cultural heritage, or experience traditional salt farming, Dasol welcomes
                        everyone with its natural beauty, rich history, and warm community.
                    </p>
                </section>

                {{-- SALT-MAKING HERITAGE --}}
                <section class="mb-5" aria-labelledby="heritage-title">
                    <h2 id="heritage-title" class="h4 mb-3">
                        Dasol's Salt-Making Heritage
                    </h2>

                    <p class="vd-prose">
                        Salt-making is an important part of Dasol's local identity
                        and livelihood. The municipality is recognized for its
                        salt-producing industry, which contributes to its cultural
                        and economic heritage.
                    </p>

                    <p class="vd-prose">
                        Visitors can learn about the traditional salt-making
                        industry and appreciate the local communities and
                        livelihoods that help shape the identity of Dasol.
                    </p>
                </section>

                {{-- HOW THIS SITE WORKS --}}
                <section class="mb-5" aria-labelledby="how-title">
                    <h2 id="how-title" class="h4">How this site works</h2>

                    <p class="vd-prose">
                        Visit Dasol brings the town's resorts and destinations together in one place, so
                        visitors can compare them and request a room without calling around.
                    </p>

                    <div class="row g-4 mt-1">
                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon">
                                    <i class="bi bi-person-walking" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="vd-feature__title">Visitors</h3>
                                    <p>Browse resorts and destinations, then request a room. A free account keeps your reservations in one place.</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon">
                                    <i class="bi bi-house-heart" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="vd-feature__title">Resort owners</h3>
                                    <p>Apply for an account, submit your resort for review, and manage your rooms and reservations.</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon">
                                    <i class="bi bi-buildings" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="vd-feature__title">Tourism office</h3>
                                    <p>Reviews every owner account and every resort before it is published, and keeps destination information current.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- WHY LISTINGS ARE REVIEWED --}}
                <section aria-labelledby="review-title">
                    <h2 id="review-title" class="h4">Why listings are reviewed</h2>

                    <p class="vd-prose">
                        A resort appears on this site only after two checks: the tourism office approves the
                        owner's account, and then approves the resort itself. If either is withdrawn, the
                        listing stops appearing and can no longer be booked. Rates, photos, and room details
                        are supplied by each resort.
                    </p>
                </section>

            </div>

            {{-- RIGHT SIDEBAR --}}
            <div class="col-lg-4">
                @php($contact = config('visitdasol.contact'))

                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">{{ $contact['office'] }}</h2>

                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2">
                            <i class="bi bi-geo-alt me-2" aria-hidden="true"></i>
                            {{ $contact['address'] }}
                        </li>

                        @if (filled($contact['phone']))
                            <li class="mb-2">
                                <i class="bi bi-telephone me-2" aria-hidden="true"></i>
                                {{ $contact['phone'] }}
                            </li>
                        @endif

                        @if (filled($contact['email']))
                            <li class="mb-2 text-break">
                                <i class="bi bi-envelope me-2" aria-hidden="true"></i>
                                <a href="mailto:{{ $contact['email'] }}">
                                    {{ $contact['email'] }}
                                </a>
                            </li>
                        @endif

                        @if (filled($contact['facebook_url']))
                            <li>
                                <i class="bi bi-facebook me-2" aria-hidden="true"></i>
                                <x-external-link :href="$contact['facebook_url']">
                                    Facebook page
                                </x-external-link>
                            </li>
                        @endif
                    </ul>
                </aside>

                <aside class="vd-panel p-4">
                    <h2 class="h5 mb-2">Own a resort in Dasol?</h2>

                    <p class="text-muted-vd small">
                        Apply for an owner account. Once the tourism office approves it, you can submit your
                        resort and manage your own rooms and reservations.
                    </p>

                    <a class="btn btn-accent" href="{{ route('register.owner') }}">
                        Apply as a resort owner
                    </a>
                </aside>
            </div>
        </div>
    </div>
@endsection