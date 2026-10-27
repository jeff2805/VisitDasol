{{--
    The Visit Dasol logo.

    <x-brand />                  on white or light backgrounds
    <x-brand :dark="true" />     on the navy sidebar, footer, and sign-in panel
    <x-brand :link="false" />    without the link to the home page

    Two image files, both made from the same artwork:
      images/logo.png          as designed, with its drop shadow
      images/logo-dark-bg.png  without the grey shadow, which looks muddy on navy
--}}
@props(['link' => true, 'dark' => false])

@php
    $tag = $link ? 'a' : 'span';

    /*
     * Both branches had been pointed at images/visitdasol.png, which
     * made $dark do nothing and put the shadowed artwork on the navy
     * sidebar, footer and sign-in panel.
     *
     * It was also the wrong file to serve: visitdasol.png is the full
     * 2172x724 original at 770 KB, shipped on every page and scaled
     * down to 160 pixels in the browser. The two files below are the
     * same artwork exported at the size it is actually displayed —
     * 65 KB and 96 KB — so this is about 700 KB off every page as well
     * as a legible logo on navy.
     */
    [$file, $width] = $dark
        ? ['images/logo-dark-bg.png', 640]
        : ['images/logo.png', 631];
@endphp

<{{ $tag }} @if ($link) href="{{ route('home') }}" @endif {{ $attributes->class('vd-brand') }}>
    <img class="vd-brand__logo" src="{{ asset($file) }}" alt="{{ config('visitdasol.name') }}"
         width="{{ $width }}" height="160">
</{{ $tag }}>
