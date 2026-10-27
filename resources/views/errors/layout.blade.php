{{--
    Standalone layout for error pages. It deliberately avoids the database,
    sessions, and the main layouts so it still renders when those fail.
    Sections: code, title, message, actions
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') | {{ config('visitdasol.name', 'Visit Dasol') }}</title>
    <link rel="icon" href="{{ asset('images/favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('vendor/fonts/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="@vdasset('css/visitdasol.css')">
</head>
<body>
    <main class="vd-error">
        <div class="text-center text-sm-start" style="max-width: 36rem">
            {{-- Plain markup, not <x-brand>: error pages must render even if routing is broken. --}}
            <a class="vd-brand mb-4" href="{{ url('/') }}">
                <img class="vd-brand__logo" src="{{ asset('images/logo.png') }}"
                     alt="{{ config('visitdasol.name', 'Visit Dasol') }}" width="631" height="160">
            </a>
            <p class="vd-error__code">@yield('code')</p>
            <h1 class="vd-error__title">@yield('title')</h1>
            <p class="vd-error__text">@yield('message')</p>
            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-sm-start mt-4">
                @yield('actions')
            </div>
        </div>
    </main>
</body>
</html>
