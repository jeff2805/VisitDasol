{{-- Public site footer. Contact details come from config/visitdasol.php. --}}
@php($contact = config('visitdasol.contact'))

<footer class="vd-footer">
    <div class="vd-saltbeds" aria-hidden="true"></div>

    <div class="container position-relative">
        <div class="row g-4">
            <div class="col-lg-4">
                <x-brand :dark="true" class="mb-3" />
                <p class="vd-footer__text">
                    Resorts, destinations, and reservations in {{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}.
                    Resort listings are reviewed by the {{ $contact['office'] }} before they are published.
                </p>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="vd-footer__heading">Explore</h2>
                <ul class="vd-footer__list">
                    @foreach (['resorts.index' => 'Rooms', 'destinations.index' => 'Destinations', 'map' => 'Map', 'announcements.index' => 'Announcements', 'about' => 'About Dasol', 'privacy' => 'Privacy notice', 'terms' => 'Terms of use'] as $name => $label)
                        <li>
                            @if (Route::has($name))
                                <a href="{{ route($name) }}">{{ $label }}</a>
                            @else
                                <span class="disabled">{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="col-6 col-lg-2">
                <h2 class="vd-footer__heading">Resort owners</h2>
                <ul class="vd-footer__list">
                    @foreach (['register.owner' => 'Apply to list a resort', 'login' => 'Owner sign in'] as $name => $label)
                        <li>
                            @if (Route::has($name))
                                <a href="{{ route($name) }}">{{ $label }}</a>
                            @else
                                <span class="disabled">{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="col-lg-4">
                <h2 class="vd-footer__heading">{{ $contact['office'] }}</h2>
                <ul class="vd-footer__list">
                    <li><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>{{ $contact['address'] }}</li>
                    @if (filled($contact['phone']))
                        <li><i class="bi bi-telephone me-2" aria-hidden="true"></i><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>
                    @endif
                    @if (filled($contact['email']))
                        <li><i class="bi bi-envelope me-2" aria-hidden="true"></i><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>
                    @endif
                    @if (filled($contact['facebook_url']))
                        <li><i class="bi bi-facebook me-2" aria-hidden="true"></i><x-external-link :href="$contact['facebook_url']">Facebook page</x-external-link></li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="vd-footer__bottom d-flex flex-column flex-md-row justify-content-between gap-2">
            <span>&copy; {{ now()->year }} {{ config('visitdasol.name') }}, {{ config('visitdasol.municipality') }}, {{ config('visitdasol.province') }}</span>
            <span>{{ config('visitdasol.tagline') }}</span>
        </div>
    </div>
</footer>
