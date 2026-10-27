{{--
    Privacy notice.

    Every factual claim below was checked against the code: the columns in the
    migrations, who each policy lets read them, and what the app sends to other
    servers. Do not soften a statement here without changing the behaviour it
    describes, or the notice stops being true.

    The parts only the office can decide are marked "The office must complete
    this" and are deliberately visible, so nobody publishes a notice that
    quietly leaves them blank.
--}}
@extends('layouts.public')

@section('title', 'Privacy notice')
@section('meta_description', 'What Visit Dasol collects about you, who can see it, and what you can ask us to do about it.')

@section('content')
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-8">
                <x-page-header title="Privacy notice"
                               subtitle="What this site collects about you, who can see it, and what you can ask us to do about it." />

                <div class="alert alert-warning d-flex gap-2" role="status">
                    <i class="bi bi-pencil-square mt-1" aria-hidden="true"></i>
                    <div>
                        <strong class="d-block">Draft for the {{ config('visitdasol.contact.office') }} to review</strong>
                        This notice describes what the system actually does. The office still has to
                        name its Data Protection Officer and decide how long records are kept before
                        this is published. Those places are marked below.
                    </div>
                </div>

                <h2 class="h5 mt-4">Who is responsible</h2>
                <p>
                    {{ config('visitdasol.contact.office') }} of {{ config('visitdasol.municipality') }},
                    {{ config('visitdasol.province') }}, operates this site and decides what is collected
                    on it. We are the personal information controller under the
                    <strong>Data Privacy Act of 2012 (Republic Act 10173)</strong>.
                </p>
                <p class="vd-todo-note">
                    <strong>The office must complete this:</strong> the name and contact details of the
                    Data Protection Officer, so that anyone with a question has a person to write to.
                </p>

                <h2 class="h5 mt-4">What we collect, and why</h2>

                <h3 class="h6 mt-3">When you make an account</h3>
                <p>
                    Your first and last name, email address, mobile number, and the city and country you
                    travel from. Your password is stored only as a cryptographic hash and cannot be read
                    back by us or by anyone else. We record the time you last signed in.
                </p>
                <p class="text-muted-vd small">
                    The city and country are used for visitor statistics. Everything else is used to
                    reach you about your account and your bookings.
                </p>

                <h3 class="h6 mt-3">When you book a room</h3>
                <p>
                    The name, mobile number, and email address you type on the booking form, your dates,
                    the number of guests and rooms, and anything you write in “special requests”.
                </p>
                <p>
                    We also ask how many of your party are male and female, and which region you are
                    travelling from. Every municipality has to report its visitor numbers to the
                    Department of Tourism each month, and these are the figures that return asks for.
                </p>
                <p class="text-muted-vd small">
                    These three answers are required, because a return with blanks in it cannot be filed.
                    They are never shown publicly and never sent to the resort. The tourism office sees
                    them on its own register, beside the name of whoever made the booking, and adds them
                    up for the figures it files.
                </p>

                <h3 class="h6 mt-3">When you write a review</h3>
                <p>
                    Your rating and what you wrote. Reviews are public. Only your first name and the
                    first letter of your surname are shown beside one, never your full name, your email,
                    or your number.
                </p>

                <h3 class="h6 mt-3">When you apply to list a resort</h3>
                <p>
                    Your business name, your position in it, your permit number, and the permit document
                    if you upload one. Permit documents are stored outside the public web folder and are
                    only ever served to the tourism office through a protected page.
                </p>

                <h3 class="h6 mt-3">While you use the site</h3>
                <p>
                    A session record holding your IP address and browser identification, so you stay
                    signed in; and an activity log of administrative actions — who approved, rejected,
                    confirmed, or changed what, and when — which is how the office can account for its
                    own decisions.
                </p>

                <h2 class="h5 mt-4">What we do not collect</h2>
                <ul>
                    <li>
                        <strong>No card or wallet details, ever.</strong> If online payment is switched
                        on, you pay on the provider’s own page. Nothing you type there reaches this site;
                        we receive only a reference and whether the payment succeeded.
                    </li>
                    <li>We do not track you across other websites, and we run no advertising.</li>
                    <li>We do not ask for your location. Your browser asks you, and only when you press “My location” on the map.</li>
                </ul>

                <h2 class="h5 mt-4">Who can see your information</h2>
                <dl class="row">
                    <dt class="col-sm-4">The resort you booked</dt>
                    <dd class="col-sm-8">
                        The name, number, and email on that booking, your dates, your party size, and
                        your requests. They need these to answer you and to expect you. They cannot see
                        your bookings at any other resort.
                    </dd>

                    <dt class="col-sm-4">{{ config('visitdasol.contact.office') }}</dt>
                    <dd class="col-sm-8">
                        Accounts, bookings, reviews, and the activity log, in order to approve listings
                        and report on tourism in the municipality.
                    </dd>

                    <dt class="col-sm-4">Other visitors</dt>
                    <dd class="col-sm-8">Only your reviews, shown as a first name and an initial.</dd>
                </dl>
                <p>
                    We do not sell your information, and we do not share it with anyone else except where
                    a law or a lawful order requires it.
                </p>

                <h2 class="h5 mt-4">Services outside this site</h2>
                <p>Using this site causes your browser or our server to contact these, and no others:</p>
                <ul>
                    <li><strong>OpenStreetMap and Esri</strong> — map and satellite images. Your browser requests them, so those servers see your IP address.</li>
                    <li><strong>OSRM</strong> — road distances. Only coordinates are sent, and only when you ask for distances on the map.</li>
                    <li><strong>Our email provider</strong> — to send you confirmation codes and booking updates.</li>
                    <li><strong>The payment provider</strong>, if online payment is switched on, and only when you choose to pay.</li>
                </ul>

                <h2 class="h5 mt-4">How long we keep it</h2>
                <p>
                    Expired one-time codes are deleted automatically. Accounts, bookings, reviews, and the
                    activity log are kept while the account exists, because a booking is a record of an
                    agreement between you and a resort.
                </p>
                <p class="vd-todo-note">
                    <strong>The office must complete this:</strong> how long records are kept after an
                    account is closed. This is a decision for the office and its records policy, not one
                    the software can make.
                </p>

                <h2 class="h5 mt-4">Your rights</h2>
                <p>Under the Data Privacy Act you may:</p>
                <ul>
                    <li>be told what we hold about you, and be given a copy;</li>
                    <li>have anything wrong corrected — your name, email, and number can be changed yourself on your profile page;</li>
                    <li>object to how we use it, or ask that it be erased or blocked;</li>
                    <li>be told if your information is ever compromised;</li>
                    <li>complain to the National Privacy Commission at <span class="text-break">privacy.gov.ph</span>.</li>
                </ul>
                <p>
                    To exercise any of these, write to the office using the details in the footer of this
                    page.
                </p>

                <h2 class="h5 mt-4">How we protect it</h2>
                <ul>
                    <li>Passwords are hashed, never stored in a readable form.</li>
                    <li>Each account can only reach its own area, checked on every request.</li>
                    <li>A resort owner can only ever open their own resort, their own rooms, and their own bookings.</li>
                    <li>Pages you see while signed in are never kept in your browser’s cache, so pressing Back after signing out on a shared computer shows nothing.</li>
                </ul>
                <p class="text-muted-vd small">
                    No system is perfectly safe. If something does go wrong, the office will tell those
                    affected and the National Privacy Commission as the law requires.
                </p>

                <h2 class="h5 mt-4">Changes</h2>
                <p class="vd-todo-note">
                    <strong>The office must complete this:</strong> the date this notice takes effect, and
                    how people will be told when it changes.
                </p>
            </div>
        </div>
    </div>
@endsection
