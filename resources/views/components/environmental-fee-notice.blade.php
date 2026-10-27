{{--
    "Dasol also charges an environmental fee."

    Shown to a tourist on the booking form and on their reservation, so
    they can budget for it and are not met by an unexpected barrier on
    the way in.

    IT IS NOT A LINE IN THE BILL, and the styling says so: it sits
    outside the price breakdown, in its own box, worded as something the
    municipality collects separately. Adding it to a booking total would
    make municipal money look like resort income, which is exactly what
    this module is built to keep apart.

    WHY NO ESTIMATED TOTAL. It would be easy to multiply the rate by the
    party size, and tempting, because a figure is more useful than a
    rate. It is not done, because who is exempt — children, seniors,
    residents — comes from an ordinance this project deliberately does
    not model. A confident wrong figure is worse than an honest rate.

    Renders nothing at all when no fee is in force, which is how the
    module ships.
--}}
@props(['policy' => null, 'checkpoints' => null])

@if ($policy)
    <aside {{ $attributes->class('vd-envfee') }} aria-labelledby="env-fee-title">
        <h3 class="vd-envfee__title" id="env-fee-title">
            <i class="bi bi-tree-fill me-1" aria-hidden="true"></i>
            Dasol's environmental fee
        </h3>

        <p class="vd-envfee__rate mb-1">
            @peso($policy->amount, true)
            <span class="vd-envfee__unit">{{ Str::lower($policy->scope->label()) }}</span>
        </p>

        <p class="vd-envfee__line mb-0">
            This is collected by the municipality at a checkpoint on your way in, and is
            <strong>separate from what you pay the resort</strong>. It is not part of the
            amount above and the resort does not collect it.
        </p>

        @if (filled($checkpoints))
            <p class="vd-envfee__line mb-0 mt-2">
                Collected at
                {{ $checkpoints->pluck('barangay')->unique()->sort()->map(fn ($b) => 'Brgy. '.$b)->join(', ', ' and ') }}.
            </p>
        @endif

        {{-- Said because the rate above is a rate, not a quote. The
             people at the gate apply the ordinance, including whatever
             it says about children, seniors and residents. --}}
        <p class="vd-envfee__line mb-0 mt-2">
            The checkpoint works out what you owe. Bring cash.
        </p>
    </aside>
@endif
