{{-- Municipal Tourism Office navigation (see Phase 0, section 27). --}}
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.dashboard" icon="speedometer2">Dashboard</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Approvals</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.owner-applications.index" match="admin.owner-applications.*" icon="person-check" :count="$pendingOwnerCount ?? null">Owner applications</x-sidebar-link>
    <x-sidebar-link route="admin.resort-applications.index" match="admin.resort-applications.*" icon="building-check" :count="$pendingResortCount ?? null">Resort applications</x-sidebar-link>
    <x-sidebar-link route="admin.spot-submissions.index" match="admin.spot-submissions.*" icon="geo-alt" :count="$pendingSpotCount ?? null">Spot submissions</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">People and listings</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.users.index" match="admin.users.*" icon="people">Users</x-sidebar-link>
    <x-sidebar-link route="admin.owners.index" match="admin.owners.*" icon="person-badge">Resort owners</x-sidebar-link>
    <x-sidebar-link route="admin.barangay-officials.index" match="admin.barangay-officials.*" icon="person-vcard">Barangay officials</x-sidebar-link>
    <x-sidebar-link route="admin.resorts.index" match="admin.resorts.*" icon="buildings">Resorts</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Tourism content</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.destinations.index" match="admin.destinations.*" icon="signpost-2">Destinations</x-sidebar-link>
    <x-sidebar-link route="admin.destination-categories.index" match="admin.destination-categories.*" icon="tags">Destination categories</x-sidebar-link>
    <x-sidebar-link route="admin.amenities.index" match="admin.amenities.*" icon="stars">Amenities</x-sidebar-link>
    <x-sidebar-link route="admin.announcements.index" match="admin.announcements.*" icon="megaphone">Announcements</x-sidebar-link>
</ul>

{{-- Its own group, not under Monitoring. This is a municipal collection
     the office runs, not something it watches other people do, and it is
     deliberately nowhere near the resort booking figures. --}}
<p class="vd-sidebar__group">Environmental fee</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.environmental-fee.index" icon="cash-stack">Fee settings</x-sidebar-link>
    <x-sidebar-link route="admin.environmental-fee.checkpoints.index" match="admin.environmental-fee.checkpoints.*" icon="ticket-detailed">Checkpoints</x-sidebar-link>
    <x-sidebar-link route="admin.environmental-fee.gcash.edit" icon="qr-code">GCash details</x-sidebar-link>
    {{-- The people who work the fee. Under this group rather than
         Accounts: a collector is hired for this module, and the office
         looks for them beside the checkpoints they stand at. --}}
    <x-sidebar-link route="admin.environmental-fee.staff.index" match="admin.environmental-fee.staff.*" icon="people">Fee staff</x-sidebar-link>
    {{-- No count badge: it would cost a query on every office page, and
         the collections report already shows the unchecked total where
         it can be acted on. --}}
    <x-sidebar-link route="admin.environmental-fee.verify" icon="patch-check">Check transfers</x-sidebar-link>
    {{-- Counting what the gates turned in. Its own entry rather than a
         tab under Collections: taking money and accounting for it are
         different jobs, often different people, and the sidebar should
         not suggest otherwise. --}}
    <x-sidebar-link route="admin.environmental-fee.remittances.index" match="admin.environmental-fee.remittances.*" icon="box-arrow-in-down">Handovers</x-sidebar-link>
    <x-sidebar-link route="admin.environmental-fee.reports" icon="receipt">Collections</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Monitoring</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="admin.bookings.index" match="admin.bookings.*" icon="journal-check">Bookings</x-sidebar-link>
    <x-sidebar-link route="admin.reviews.index" match="admin.reviews.*" icon="chat-quote">Reviews</x-sidebar-link>
    <x-sidebar-link route="admin.map" icon="map">Map</x-sidebar-link>
    <x-sidebar-link route="admin.reports.index" match="admin.reports.*" icon="graph-up">Reports</x-sidebar-link>
    <x-sidebar-link route="admin.activity-logs.index" icon="clock-history">Activity log</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Account</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="profile.edit" icon="person-circle">Profile</x-sidebar-link>
    @if (Route::has('logout'))
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="vd-sidebar__link">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Sign out</span>
                </button>
            </form>
        </li>
    @endif
</ul>
