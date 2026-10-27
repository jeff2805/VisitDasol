{{--
    Base HTML document shared by every layout (public, auth, owner, admin).

    Sections a page or layout may define:
      title             page title (the site name is appended)
      meta_description  search/social description
      body_class        classes for <body>
      body              the full page body (layouts fill this)

    Stacks:
      styles            extra <link>/<style> tags, e.g. Leaflet CSS
      scripts           extra <script> tags, loaded after Bootstrap and app.js
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover lets the stylesheet read env(safe-area-inset-*), so
         nothing hides under an iPhone's notch or an Android gesture bar.
         Zooming is deliberately left alone: pinching to read is a right. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('meta_description', 'Resorts, destinations, and reservations in Dasol, Pangasinan.')">
    <meta name="theme-color" content="#0D3B47">

    <title>@hasSection('title')@yield('title') | @endif{{ config('visitdasol.name') }}</title>

    <link rel="icon" href="{{ asset('images/visitdasol.png') }}" type="image/visitdasol">
    <link rel="preload" href="{{ asset('vendor/fonts/files/figtree-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('vendor/fonts/files/bricolage-grotesque-latin-standard-normal.woff2') }}" as="font" type="font/woff2" crossorigin>

    <link rel="stylesheet" href="{{ asset('vendor/fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @stack('styles')
    <link rel="stylesheet" href="@vdasset('css/visitdasol.css')">
</head>
<body class="@yield('body_class')">
    <a class="vd-skip-link" href="#main-content">Skip to main content</a>

    @yield('body')

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="@vdasset('js/app.js')"></script>
    @stack('scripts')
</body>
</html>
