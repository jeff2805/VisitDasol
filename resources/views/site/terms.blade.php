{{--
    Terms of use.

    Describes how the system actually behaves: a booking is a request until a
    resort accepts it, the office approves listings, and no money changes hands
    here. Changing any of those behaviours means changing this page too.
--}}
@extends('layouts.public')

@section('title', 'Terms of use')
@section('meta_description', 'The rules for using Visit Dasol: booking requests, resort listings, reviews, and what this site does and does not do.')

@section('content')
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-8">
                <x-page-header title="Terms of use"
                               subtitle="The rules for using this site, in plain words." />

                <div class="alert alert-warning d-flex gap-2" role="status">
                    <i class="bi bi-pencil-square mt-1" aria-hidden="true"></i>
                    <div>
                        <strong class="d-block">Draft for the {{ config('visitdasol.contact.office') }} to review</strong>
                        These terms describe how the system behaves today. The office should have them
                        checked before publishing, and set the date they take effect.
                    </div>
                </div>

                <h2 class="h5 mt-4">What this site is</h2>
                <p>
                    {{ config('visitdasol.name') }} is run by {{ config('visitdasol.contact.office') }}
                    to help visitors find resorts and places to visit in
                    {{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}, and to
                    send booking requests to those resorts.
                </p>
                <p>
                    <strong>We are not the seller.</strong> Each resort is an independent business. Your
                    stay is an agreement between you and that resort. This site introduces you to each
                    other and carries the request.
                </p>

                <h2 class="h5 mt-4">Accounts</h2>
                <ul>
                    <li>Give details that are true. A resort has to be able to reach you.</li>
                    <li>Confirm your email with the code we send. Until you do, you cannot book.</li>
                    <li>Your account is yours. Keep the password to yourself and tell the office if you think someone else has it.</li>
                    <li>A resort owner account has to be approved by the office before it can be used.</li>
                </ul>

                <h2 class="h5 mt-4">Booking</h2>
                <ul>
                    <li>
                        <strong>Sending a booking is a request, not a reservation.</strong> The resort
                        decides whether to accept it. You are only booked once it says confirmed.
                    </li>
                    <li>While a request is waiting, the rooms are held for you.</li>
                    <li>A resort may decline, and will normally give a reason.</li>
                    <li>You may cancel before the stay begins. Once it has begun, speak to the resort.</li>
                    <li>You may have up to {{ config('visitdasol.booking.max_pending_per_tourist') }} requests waiting at once, so rooms are not held on requests nobody intends to keep.</li>
                    <li>Stays of more than {{ config('visitdasol.booking.max_nights') }} nights, or more than {{ config('visitdasol.booking.max_advance_days') }} days ahead, are arranged with the resort directly.</li>
                </ul>

                <h2 class="h5 mt-4">Prices and payment</h2>
                <ul>
                    <li>Rates are set by each resort and shown per room, per night.</li>
                    <li>The figure you see is what the resort expects to collect. The final amount is worked out by the resort.</li>
                    <li>
                        Where online payment is offered, it is optional and only after a resort has
                        confirmed. You pay on the provider’s own page. Refunds and disputes are between
                        you and the resort, under the provider’s rules.
                    </li>
                </ul>

                <h2 class="h5 mt-4">Reviews</h2>
                <ul>
                    <li>Only a guest who actually completed a stay can review it, once per stay.</li>
                    <li>Write about your own stay. No abuse, no personal details of other people, nothing untrue.</li>
                    <li>A resort may reply once, in public.</li>
                    <li>The office may hide a review that breaks these rules, and records why. Hiding is not deleting: the record remains.</li>
                    <li>A resort owner cannot hide a review of their own resort. If they could, no rating on this site would be worth reading.</li>
                </ul>

                <h2 class="h5 mt-4">If you list a resort</h2>
                <ul>
                    <li>Describe your resort truthfully, including the location pin, the rates, and what is included.</li>
                    <li>Only upload photographs you have the right to use.</li>
                    <li>Every listing is reviewed by the office before visitors can see it, and again if it is resubmitted.</li>
                    <li>Answer booking requests promptly. Guests are waiting on you.</li>
                    <li>Guest details are given to you to serve that booking, and for nothing else. Do not use them for marketing or pass them on.</li>
                    <li>The office may take a listing offline if it is inaccurate or if guests are not being looked after.</li>
                </ul>

                <h2 class="h5 mt-4">What you may not do</h2>
                <ul>
                    <li>Book rooms you do not intend to take, or make accounts that are not yours.</li>
                    <li>Try to reach another person’s bookings, account, or resort.</li>
                    <li>Interfere with the site, or collect information from it automatically.</li>
                    <li>Post anything unlawful, or anything belonging to someone else.</li>
                </ul>

                <h2 class="h5 mt-4">What we can and cannot promise</h2>
                <p>
                    The office checks listings before they are published, but the details, photographs,
                    rates, and location pins come from the resorts. Treat them as a guide and confirm
                    what matters with the resort before you travel.
                </p>
                <p>
                    Map data comes from OpenStreetMap and Esri, and road distances are estimates for
                    driving. They do not know about closures or the state of a road.
                </p>
                <p>
                    This site may be unavailable at times for maintenance or for reasons outside the
                    office’s control.
                </p>

                <h2 class="h5 mt-4">Your information</h2>
                <p>
                    How your information is handled is set out in the
                    <a href="{{ route('privacy') }}">privacy notice</a>.
                </p>

                <h2 class="h5 mt-4">Changes and questions</h2>
                <p class="vd-todo-note">
                    <strong>The office must complete this:</strong> the date these terms take effect, how
                    people will be told when they change, and which venue settles a dispute.
                </p>
                <p>
                    Questions go to {{ config('visitdasol.contact.office') }} using the details in the
                    footer below.
                </p>
            </div>
        </div>
    </div>
@endsection
