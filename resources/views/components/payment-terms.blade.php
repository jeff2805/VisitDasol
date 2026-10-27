{{--
    What a reservation has to pay, and by when.

    Shown to the guest on the booking form (from the live policy) and on
    the reservation page (from the booking's own snapshot), and to the
    owner on each reservation. One component, so the three cannot drift
    into quoting different figures.

    paid is optional: on the booking form nothing has been paid yet, so
    it is left out and the "already paid" line does not appear.
--}}
@props(['terms', 'paid' => null, 'heading' => 'What you pay'])

@php
    $needsPayment = $terms->needsAdvancePayment();
    $settled = $paid !== null ? (float) $paid : null;
@endphp

<div {{ $attributes->class('vd-terms') }}>
    <h3 class="vd-terms__title">{{ $heading }}</h3>

    <dl class="vd-terms__lines">
        <dt>Total for the stay</dt>
        <dd>@peso($terms->total, true)</dd>

        @if ($needsPayment)
            <dt>
                {{ $terms->requirement === \App\Enums\PaymentRequirement::Full
                    ? 'To pay before arrival'
                    : 'Deposit to pay ('.$terms->depositPercent.'%)' }}
            </dt>
            <dd class="vd-terms__due">@peso($terms->dueNow, true)</dd>

            {{-- Only when there is one. "Balance ₱0.00" under a full
                 payment is a line that answers a question nobody asked. --}}
            @if ($terms->balance !== '0.00')
                <dt>Balance, due at the resort</dt>
                <dd>@peso($terms->balance, true)</dd>
            @endif
        @else
            <dt>To pay before arrival</dt>
            <dd class="vd-terms__due">Nothing</dd>
        @endif

        @if ($settled !== null && $settled > 0)
            <dt>Already paid</dt>
            <dd class="text-success">@peso($settled, true)</dd>
        @endif
    </dl>

    {{-- The terms in a sentence. Written by PaymentTerms itself so the
         wording cannot differ between the form and the confirmation. --}}
    <p class="vd-terms__sentence mb-0">{{ $terms->sentence() }}</p>

    @if ($needsPayment && $terms->lastMinute)
        <p class="vd-terms__note mb-0">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
            This resort asks for more up front when a guest is arriving soon,
            and that is what applies here.
        </p>
    @endif

    @if (! $needsPayment && $terms->onSiteAllowed)
        <p class="vd-terms__note mb-0">
            <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>
            Sending this request is not a payment, and confirming it is not a payment either.
            The resort collects when you arrive.
        </p>
    @endif
</div>
