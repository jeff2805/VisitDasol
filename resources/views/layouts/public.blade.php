{{--
    Public website layout: guests and signed-in tourists.
    Pages use:  @extends('layouts.public')  and  @section('content')
--}}
@extends('layouts.base')

@section('body_class', 'd-flex flex-column min-vh-100')

@section('body')
    @include('partials.navbar')

    <main id="main-content" class="flex-grow-1" tabindex="-1">
        @if (session()->hasAny(['success', 'error', 'warning', 'info']) || (isset($errors) && $errors->any()))
            <div class="container pt-3">
                @include('partials.flash')
            </div>
        @endif

        @yield('content')
    </main>

    @include('partials.footer')
@endsection
