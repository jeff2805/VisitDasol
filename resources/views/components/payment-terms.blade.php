{{--
    What a reservation has to pay, and by when.

    Shown to the guest on the booking form (from the live policy) and on
    the reservation page (from the booking's own snapshot), and to the
    owner on each reservation. One component, so the three cannot drift
    into quoting different figures.

    paid is optional: on the booking form nothing has been paid yet, so
    it is left out and the "already paid" line does not appear.

    THE data-terms-* HOOKS ARE FOR THE BOOKING FORM ONLY.

    There the guest changes the room, the dates or the party and the
    figures have to follow without a reload. booking-form.js asks the
    server for new terms and writes them into these hooks.

    It only sets text and toggles hidden. Every decision — whether
    anything is due, whether a balance remains, whether the last-minute
    rule applied, how the sentence reads — is made by
    PaymentPolicyService and arrives already made. Nothing about a
    resort's policy is worked out in the browser, and the percentages
    and deadlines are never written into the script.

    That is also why both branches below are rendered, with the
    inactive one hidden: the script can switch between them without
    building markup, and so without owning any of the wording.
--}}
@props(['terms', 'paid' => null, 'heading' => 'What you pay'])

@php
    $needsPayment = $terms->needsAdvancePayment();
    $settled = $paid !== null ? (float) $paid : null;
    $hasBalance = $terms->balance !== '0.00';
@endphp

<div {{ $attributes->class('vd-terms') }} data-terms>
    <h3 class="vd-terms__title">{{ $heading }}</h3>

    <dl class="vd-terms__lines">
        <dt>Total for the stay</dt>
        <dd data-terms-total>@peso($terms->total, true)</dd>

        {{-- Something to pay before arrival. --}}
        <dt data-terms-due-label @unless ($needsPayment) hidden @endunless>
            {{ $terms->requirement === \App\Enums\PaymentRequirement::Full
                ? 'To pay before arrival'
                : 'Deposit to pay ('.$terms->depositPercent.'%)' }}
        </dt>
        <dd class="vd-terms__due" data-terms-due @unless ($needsPayment) hidden @endunless>
            @peso($terms->dueNow, true)
        </dd>

        {{-- Only when there is one. "Balance ₱0.00" under a full
             payment is a line that answers a question nobody asked. --}}
        <dt data-terms-balance-label @unless ($needsPayment && $hasBalance) hidden @endunless>
            Balance, due at the resort
        </dt>
        <dd data-terms-balance @unless ($needsPayment && $hasBalance) hidden @endunless>
            @peso($terms->balance, true)
        </dd>

        {{-- Nothing to pay before arrival. --}}
        <dt data-terms-nothing-label @if ($needsPayment) hidden @endif>To pay before arrival</dt>
        <dd class="vd-terms__due" data-terms-nothing @if ($needsPayment) hidden @endif>Nothing</dd>

        @if ($settled !== null && $settled > 0)
            <dt>Already paid</dt>
            <dd class="text-success">@peso($settled, true)</dd>
        @endif
    </dl>

    {{-- The terms in a sentence. Written by PaymentTerms itself so the
         wording cannot differ between the form and the confirmation. --}}
    <p class="vd-terms__sentence mb-0" data-terms-sentence>{{ $terms->sentence() }}</p>

    <p class="vd-terms__note mb-0" data-terms-lastminute
       @unless ($needsPayment && $terms->lastMinute) hidden @endunless>
        <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
        This resort asks for more up front when a guest is arriving soon,
        and that is what applies here.
    </p>

    <p class="vd-terms__note mb-0" data-terms-onsite
       @unless (! $needsPayment && $terms->onSiteAllowed) hidden @endunless>
        <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>
        Sending this request is not a payment, and confirming it is not a payment either.
        The resort collects when you arrive.
    </p>

    {{-- Only ever shown by the script, and only when the server could not
         be reached. The figures above are then the last good ones, which
         is why this says so rather than blanking them. --}}
    <p class="vd-terms__note mb-0 text-danger" data-terms-error role="alert" hidden>
        <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
        These figures could not be updated just now. They are confirmed on the
        next screen, and nothing is charged until the resort accepts.
    </p>
</div>
