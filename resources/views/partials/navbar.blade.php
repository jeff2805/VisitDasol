{{-- Public site navigation. Links to routes from later phases render disabled until they exist. --}}
<nav class="navbar navbar-expand-lg vd-navbar sticky-top" aria-label="Main">
    <div class="container">
        <x-brand class="navbar-brand" />

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#vdMainNav"
                aria-controls="vdMainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="vdMainNav">
            <ul class="navbar-nav mx-lg-auto mt-3 mt-lg-0">
                <x-nav-link route="home">Home</x-nav-link>
                {{-- "Rooms", because that is what the page lists. The URL and
                     the route name stay "resorts": renaming those would break
                     every stored link for no visible gain, and a label is
                     allowed to differ from an address. --}}
                <x-nav-link route="resorts.index" match="resorts.*">Rooms</x-nav-link>
                <x-nav-link route="destinations.index" match="destinations.*">Destinations</x-nav-link>
                <x-nav-link route="map">Map</x-nav-link>
                <x-nav-link route="announcements.index" match="announcements.*">Announcements</x-nav-link>
                <x-nav-link route="about">About</x-nav-link>

                {{-- A tourist's own reservations are not a section of the site
                     the way Rooms and the Map are, so they live under the
                     account menu with Profile and Sign out. They used to be
                     here as well, which put two links to one page in one bar
                     under two different names, and offered a signed-out visitor
                     a link that could only bounce them to the sign-in page. --}}
            </ul>

            <div class="d-flex flex-column flex-lg-row gap-2 py-3 py-lg-0">
                @auth
                    @if (Route::has('dashboard'))
                        <a class="btn btn-outline-primary" href="{{ route('dashboard') }}">
                            <i class="bi bi-grid me-1" aria-hidden="true"></i>Dashboard
                        </a>
                    @endif
                    <div class="dropdown">
                        <button class="btn btn-light border w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            {{ auth()->user()->first_name }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-lg-end">
                            {{-- "My reservations" everywhere: the page's own
                                 heading, this link, and the dashboard's button
                                 all name it the same thing. Only a tourist is
                                 offered it — the route refuses an owner or the
                                 office, and both reach their own reservations
                                 through the Dashboard button. --}}
                            @if (auth()->user()->isTourist() && Route::has('tourist.bookings.index'))
                                <li><a class="dropdown-item" href="{{ route('tourist.bookings.index') }}">My reservations</a></li>
                            @endif
                            @if (Route::has('profile.edit'))
                                <li><a class="dropdown-item" href="{{ route('profile.edit') }}">Profile</a></li>
                            @endif
                            @if (Route::has('logout'))
                                <li>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">Sign out</button>
                                    </form>
                                </li>
                            @endif
                        </ul>
                    </div>
                @else
                    @if (Route::has('login'))
                        <a class="btn btn-link text-decoration-none fw-semibold" href="{{ route('login') }}">Sign in</a>
                    @endif
                    @if (Route::has('register'))
                        <div class="dropdown">
                            <button class="btn btn-accent w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Create account
                            </button>
                            <ul class="dropdown-menu dropdown-menu-lg-end">
                                <li>
                                    <a class="dropdown-item py-2" href="{{ route('register') }}">
                                        <strong class="d-block">Tourist account</strong>
                                        <span class="small text-muted-vd">Book rooms and track reservations</span>
                                    </a>
                                </li>
                                @if (Route::has('register.owner'))
                                    <li>
                                        <a class="dropdown-item py-2" href="{{ route('register.owner') }}">
                                            <strong class="d-block">Resort owner application</strong>
                                            <span class="small text-muted-vd">Reviewed by the Municipal Tourism Office</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</nav>
