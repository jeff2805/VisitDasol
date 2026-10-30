{{--
    Shared dashboard shell for the resort owner and MTO areas.
    Do not extend this directly; use layouts.owner or layouts.admin.

    The top bar runs the full width of the window and the sidebar hangs below
    it, so the logo sits on white above the navy column.

    Sections:
      area_label   shown under the logo in the sidebar
      sidebar      the navigation list (provided by owner/admin layouts)
      topbar       optional extras between the menu button and the account
                   menu, e.g. the office search box
      content      the page

    The top bar carries no page title: every page states its own with
    <x-page-header>, which is also the page's only <h1>.
--}}
@extends('layouts.base')

@section('body_class', 'vd-dashboard')

@section('body')
    <div class="vd-shell">
        <header class="vd-topbar">
            {{-- Over the sidebar column, so the logo and the menu share one line. --}}
            <div class="vd-topbar__brand">
                <x-brand />
            </div>

            {{-- Driven by app.js, not Bootstrap's data API: at large sizes the
                 sidebar is not an offcanvas, and opening one there would drop a
                 backdrop over the page. --}}
            <button class="vd-topbar__menu" type="button" data-sidebar-toggle
                    aria-controls="vdSidebar" aria-expanded="true" aria-label="Show or hide the menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            @yield('topbar')

            @auth
                <div class="dropdown vd-topbar__account">
                    <button class="vd-account" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="vd-avatar">{{ auth()->user()->initials }}</span>
                        <span class="vd-account__who">
                            <span class="vd-account__name">{{ auth()->user()->full_name }}</span>
                            <span class="vd-account__role">{{ auth()->user()->role->label() }}</span>
                        </span>
                        <i class="bi bi-chevron-down vd-account__caret" aria-hidden="true"></i>
                        <span class="visually-hidden">Account menu</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="px-3 py-2">
                            <strong class="d-block">{{ auth()->user()->full_name }}</strong>
                            <span class="small text-muted-vd">{{ auth()->user()->email }}</span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        @if (Route::has('profile.edit'))
                            <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person me-2" aria-hidden="true"></i>Profile</a></li>
                        @endif
                        <li><x-external-link class="dropdown-item d-xl-none" :href="route('home')"><i class="bi bi-box-arrow-up-right me-2" aria-hidden="true"></i>View public site</x-external-link></li>
                        @if (Route::has('logout'))
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
                                </form>
                            </li>
                        @endif
                    </ul>
                </div>
            @else
                <span class="vd-avatar" title="Layout preview (not signed in)">PV</span>
            @endauth
        </header>

        <div class="vd-shell__body">
            <div class="vd-sidebar-rail">
                <aside class="offcanvas-lg offcanvas-start vd-sidebar" tabindex="-1" id="vdSidebar" aria-labelledby="vdSidebarLabel vdSidebarArea">
                    <div class="vd-sidebar__inner">
                        {{-- The offcanvas needs its own heading and close button on small
                             screens; on large ones the top bar already carries the logo. --}}
                        <div class="vd-sidebar__header">
                            <div id="vdSidebarLabel" class="d-flex">
                                <x-brand :dark="true" />
                            </div>
                            <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#vdSidebar" aria-label="Close menu"></button>
                        </div>

                        <nav class="vd-sidebar__nav" aria-label="Dashboard">
                            <p class="vd-sidebar__area" id="vdSidebarArea">@yield('area_label')</p>
                            @yield('sidebar')
                        </nav>

                        {{-- The tagline, standing on the shoreline at the foot of the menu. --}}
                        <div class="vd-sidebar__sign" aria-hidden="true">
                            <x-palm-fronds side="left" />


                            <div class="vd-saltbeds vd-sidebar__beds"></div>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="vd-main">
                <main id="main-content" class="vd-content" tabindex="-1">
                    @include('partials.flash')

                    @yield('content')
                </main>
            </div>
        </div>
    </div>
@endsection
