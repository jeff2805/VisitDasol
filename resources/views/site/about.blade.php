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

                <section class="mb-5" aria-labelledby="municipality-title">
                    <h2 id="municipality-title" class="h4">The municipality</h2>
                    <p class="vd-prose">
                        Dasol is a coastal municipality in the western part of Pangasinan, in the Ilocos Region
                        of the Philippines. It is made up of 18 barangays and uses the postal code
                        {{ config('visitdasol.postal_code') }}. The town is long associated with salt-making,
                        and its shoreline faces Dasol Bay.
                    </p>
                    <p class="vd-prose text-muted-vd">
                        For official information about the municipality, its services, and current travel
                        advisories, please contact the {{ config('visitdasol.contact.office') }}.
                    </p>
                </section>

                <section class="mb-5" aria-labelledby="how-title">
                    <h2 id="how-title" class="h4">How this site works</h2>
                    <p class="vd-prose">
                        Visit Dasol brings the town's resorts and destinations together in one place, so
                        visitors can compare them and request a room without calling around.
                    </p>

                    <div class="row g-4 mt-1">
                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon"><i class="bi bi-person-walking" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="vd-feature__title">Visitors</h3>
                                    <p>Browse resorts and destinations, then request a room. A free account keeps your reservations in one place.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon"><i class="bi bi-house-heart" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="vd-feature__title">Resort owners</h3>
                                    <p>Apply for an account, submit your resort for review, and manage your rooms and reservations.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="vd-feature">
                                <span class="vd-feature__icon"><i class="bi bi-buildings" aria-hidden="true"></i></span>
                                <div>
                                    <h3 class="vd-feature__title">Tourism office</h3>
                                    <p>Reviews every owner account and every resort before it is published, and keeps destination information current.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

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

            <div class="col-lg-4">
                @php($contact = config('visitdasol.contact'))
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">{{ $contact['office'] }}</h2>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2"><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>{{ $contact['address'] }}</li>
                        @if (filled($contact['phone']))
                            <li class="mb-2"><i class="bi bi-telephone me-2" aria-hidden="true"></i>{{ $contact['phone'] }}</li>
                        @endif
                        @if (filled($contact['email']))
                            <li class="mb-2 text-break"><i class="bi bi-envelope me-2" aria-hidden="true"></i><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>
                        @endif
                        @if (filled($contact['facebook_url']))
                            <li><i class="bi bi-facebook me-2" aria-hidden="true"></i><x-external-link :href="$contact['facebook_url']">Facebook page</x-external-link></li>
                        @endif
                    </ul>
                </aside>

                <aside class="vd-panel p-4">
                    <h2 class="h5 mb-2">Own a resort in Dasol?</h2>
                    <p class="text-muted-vd small">
                        Apply for an owner account. Once the tourism office approves it, you can submit your
                        resort and manage your own rooms and reservations.
                    </p>
                    <a class="btn btn-accent" href="{{ route('register.owner') }}">Apply as a resort owner</a>
                </aside>
            </div>
        </div>
    </div>
@endsection
