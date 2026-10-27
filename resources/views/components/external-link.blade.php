{{--
    A link that opens in a new tab, and says so.

    <x-external-link :href="route('terms')">terms of use</x-external-link>
    <x-external-link class="btn btn-outline-primary" :href="$url">View page</x-external-link>

    A tab appearing out of nowhere is disorienting for anybody, and for
    somebody using a screen reader it is worse: the back button no longer
    goes back, and nothing said why. The note is hidden visually and read
    aloud, so sighted visitors see no clutter.

    rel="noopener" by default, because a page opened this way can otherwise
    reach back into the one that opened it. A caller may pass its own rel
    (adding nofollow for somebody else's website, say) and that wins.
--}}
@props(['href'])

<a {{ $attributes->merge(['href' => $href, 'target' => '_blank', 'rel' => 'noopener']) }}>{{ $slot }}<span class="visually-hidden"> (opens in a new tab)</span></a>
