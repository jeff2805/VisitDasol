{{-- Barangay official navigation. A short menu on purpose: this account does
     one job, which is writing up the places in its own barangay. --}}
<ul class="vd-sidebar__list">
    <x-sidebar-link route="barangay.dashboard" icon="speedometer2">Dashboard</x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Barangay {{ auth()->user()?->barangay }}</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="barangay.spots.index" match="barangay.spots.*" icon="geo-alt">Destinations</x-sidebar-link>
    <x-sidebar-link route="barangay.spots.create" icon="plus-circle">Add a spot</x-sidebar-link>
</ul>

{{-- The environmental fee, for officials who also work a gate.

     Shown to every barangay official rather than only to those with an
     assignment. Working out who is assigned would cost a query on every
     page of the dashboard, and the screens themselves say plainly when
     somebody has not been added to a checkpoint yet — which is a better
     answer than a missing menu item, and the same reasoning
     EnvironmentalFeeCollectionPolicy::viewAny() already sets out.

     The policies still decide. Nothing here grants anything. --}}
<p class="vd-sidebar__group">Environmental fee</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="environmental-fee.index" icon="ticket-detailed">Collect</x-sidebar-link>
    <x-sidebar-link route="environmental-fee.remittances.index" match="environmental-fee.remittances.*" icon="box-arrow-in-down">Hand in takings</x-sidebar-link>
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
