{{--
    "Continue with Google" and "Continue with Facebook".

    <x-social-buttons />
    <x-social-buttons verb="Sign up" />

    Renders nothing at all when no provider has keys set, so an installation
    that has not registered with Google or Facebook shows no buttons rather
    than buttons that lead to an error.

    These create TOURIST accounts only. A resort owner's account is approved
    by the office before it works, so it cannot be handed out by a button.
--}}
@props(['verb' => 'Continue'])

@php($providers = \App\Enums\SocialProvider::available())

@if ($providers !== [])
    <div class="vd-social">
        <p class="vd-social__or"><span>or</span></p>

        @foreach ($providers as $provider)
            {{-- A link, not a form: this is a plain redirect to somebody
                 else's sign-in page and nothing of ours is submitted. --}}
            <a class="vd-social__button vd-social__button--{{ $provider->value }}"
               href="{{ route('social.redirect', $provider->value) }}"
               rel="nofollow">
                <i class="bi bi-{{ $provider->icon() }}" aria-hidden="true"></i>
                <span>{{ $verb }} with {{ $provider->label() }}</span>
            </a>
        @endforeach

        <p class="vd-social__note">
            We only ever ask for your name and email address, and only to reach
            you about a booking.
        </p>
    </div>
@endif
