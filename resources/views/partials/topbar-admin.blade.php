{{--
    Municipal Tourism Office top bar: quick search and the review bell.

    $pendingOwnerCount and $pendingResortCount come from the view composer in
    AppServiceProvider, the same one that feeds the sidebar badges.
--}}
@php
    $waiting = ($pendingOwnerCount ?? 0) + ($pendingResortCount ?? 0);
@endphp

{{--
    Enter searches resorts, the form's own action. The menu offers the other
    lists with formaction, which is plain HTML and needs no JavaScript.
--}}
<form class="vd-search" method="GET" action="{{ route('admin.resorts.index') }}" role="search">
    <button class="vd-search__go" type="submit">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span class="visually-hidden">Search resorts</span>
    </button>

    <input class="vd-search__field" type="search" name="q" value="{{ request()->routeIs('admin.resorts.index') ? request('q') : '' }}"
           placeholder="Search resorts, bookings, users…" aria-label="Search">

    <div class="dropdown">
        <button class="vd-search__scope" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
            <span class="visually-hidden">Search somewhere else</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" type="submit" formaction="{{ route('admin.resorts.index') }}">Resorts</button></li>
            <li><button class="dropdown-item" type="submit" formaction="{{ route('admin.bookings.index') }}">Bookings</button></li>
            <li><button class="dropdown-item" type="submit" formaction="{{ route('admin.users.index') }}">Users</button></li>
            <li><button class="dropdown-item" type="submit" formaction="{{ route('admin.destinations.index') }}">Destinations</button></li>
        </ul>
    </div>
</form>

<div class="dropdown">
    <button class="vd-bell" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-bell" aria-hidden="true"></i>
        @if ($waiting > 0)
            <span class="vd-bell__count">{{ $waiting > 99 ? '99+' : $waiting }}</span>
        @endif
        <span class="visually-hidden">
            {{ $waiting === 1 ? 'One application is' : $waiting.' applications are' }} waiting for review
        </span>
    </button>

    <ul class="dropdown-menu dropdown-menu-end vd-bell__menu">
        <li class="dropdown-header">Waiting for review</li>

        @if ($waiting === 0)
            <li><p class="dropdown-item-text text-muted-vd mb-0">Nothing is waiting. You are all caught up.</p></li>
        @else
            @if (($pendingOwnerCount ?? 0) > 0)
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('admin.owner-applications.index') }}">
                        <i class="bi bi-person-check" aria-hidden="true"></i>
                        <span class="flex-grow-1">Owner applications</span>
                        <span class="vd-sidebar__count">{{ $pendingOwnerCount }}</span>
                    </a>
                </li>
            @endif

            @if (($pendingResortCount ?? 0) > 0)
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('admin.resort-applications.index') }}">
                        <i class="bi bi-building-check" aria-hidden="true"></i>
                        <span class="flex-grow-1">Resort applications</span>
                        <span class="vd-sidebar__count">{{ $pendingResortCount }}</span>
                    </a>
                </li>
            @endif
        @endif
    </ul>
</div>
