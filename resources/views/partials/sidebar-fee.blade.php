{{--
    Environmental fee staff navigation: collectors and cashiers.

    Two jobs, two menus, one file, because the two accounts share the
    module and nothing else. A collector takes money at a gate and hands
    it in; a cashier counts what came in and reads the municipal figures.
    Neither is the tourism office, so neither gets the office's menu.

    WHERE THE LINKS POINT DIFFERS BY ROLE ON PURPOSE.
    environmental-fee.remittances.index shows a person THEIR OWN
    handovers (the controller filters on collector_id), which is the
    right screen for a collector and an empty one for a cashier. The
    cashier's equivalent is the office-wide list under admin/, so that
    is what this menu gives them.

    Nothing here grants anything. The policies decide, and a cashier is
    refused the fee settings, the checkpoints, the GCash account and
    staff creation whether or not a link appears.
--}}
@php
    $user = auth()->user();
@endphp

@if ($user?->isFeeCollector())
    {{-- The gate. Shown to every collector rather than only to those
         with a checkpoint assignment: working out who is assigned would
         cost a query on every page, and the screen itself says plainly
         when somebody has not been added to a checkpoint yet, which
         tells them more than a missing menu item would. --}}
    <p class="vd-sidebar__group">At the checkpoint</p>
    <ul class="vd-sidebar__list">
        <x-sidebar-link route="environmental-fee.index" icon="ticket-detailed">Collect</x-sidebar-link>
        <x-sidebar-link route="environmental-fee.remittances.index" match="environmental-fee.remittances.*" icon="box-arrow-in-down">Hand in takings</x-sidebar-link>
    </ul>
@endif

@if ($user?->isCashier())
    <p class="vd-sidebar__group">Counting what came in</p>
    <ul class="vd-sidebar__list">
        <x-sidebar-link route="admin.environmental-fee.remittances.index" match="admin.environmental-fee.remittances.*" icon="box-arrow-in-down">Handovers</x-sidebar-link>
        <x-sidebar-link route="admin.environmental-fee.verify" icon="patch-check">Check transfers</x-sidebar-link>
    </ul>

    <p class="vd-sidebar__group">The figures</p>
    <ul class="vd-sidebar__list">
        <x-sidebar-link route="admin.environmental-fee.reports" icon="receipt">Collections</x-sidebar-link>
        <x-sidebar-link route="admin.environmental-fee.lookup" icon="search">Look up a receipt</x-sidebar-link>
    </ul>
@endif

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
