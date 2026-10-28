{{--
    The menu that belongs to whoever is signed in.

    WHY THIS EXISTS. layouts.dashboard yields a "sidebar" section, and
    layouts.admin / owner / barangay each fill it in. But several screens
    are shared by four different roles — the environmental fee ones are
    reached by the office, barangay officials, collectors and cashiers —
    so they extend layouts.dashboard directly and filled in nothing.
    Collectors and cashiers were getting a navy column with no links in
    it at all, and no way out of the page but the browser's back button.

    Rather than thread a $layout variable through every controller that
    serves a shared screen, the layout falls back to this and this picks
    by role. A page that wants a particular menu still wins: it defines
    the section and the layout never reaches here.

    NOT AUTHORISATION. This decides what a menu shows, nothing more.
    Every route is gated by its own middleware and policy.
--}}
@php
    $partial = match (auth()->user()?->role) {
        \App\Enums\UserRole::SuperAdmin => 'partials.sidebar-admin',
        \App\Enums\UserRole::ResortOwner => 'partials.sidebar-owner',
        \App\Enums\UserRole::BarangayOfficial => 'partials.sidebar-barangay',
        \App\Enums\UserRole::FeeCollector, \App\Enums\UserRole::Cashier => 'partials.sidebar-fee',

        // A tourist, or nobody at all. Tourists use the public navbar and
        // never see this shell; an unauthenticated render is the layout
        // preview. Either way there is no dashboard menu to show.
        default => null,
    };
@endphp

@if ($partial !== null)
    @include($partial)
@endif
