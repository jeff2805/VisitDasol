@extends('layouts.public')

@section('title', 'Reservation '.$booking->booking_reference)

@php($resort = $booking->resort)

{{-- Loaded only where the card form is drawn, so a reservation that is not
     payable carries no payment code at all. --}}
@if ($cardPayable ?? false)
    @push('scripts')
        <script src="@vdasset('js/card-payment.js')" defer></script>
    @endpush
@endif

@section('content')
    <div class="container py-5">
        <nav aria-label="Breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="{{ route('tourist.dashboard') }}">My account</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tourist.bookings.index') }}">My reservations</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $booking->booking_reference }}</li>
            </ol>
        </nav>

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4 mb-4">
                    <p class="vd-card__eyebrow mb-1">Your reference</p>
                    <h1 class="vd-display mb-2" style="font-size: clamp(1.75rem, 1.2rem + 2vw, 2.5rem)">
                        {{ $booking->booking_reference }}
                    </h1>
                    <p class="mb-3"><x-status-badge :status="$booking->status" /></p>

                    {{-- What this status means for the guest --}}
                    @switch($booking->status)
                        @case(\App\Enums\BookingStatus::Pending)
                            <div class="alert alert-warning d-flex gap-2 mb-0" role="status">
                                <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong class="d-block">Waiting for the resort</strong>
                                    Your rooms are held while {{ $resort->name }} answers. You will see the result here.
                                </div>
                            </div>
                            @break

                        @case(\App\Enums\BookingStatus::Confirmed)
                            <div class="alert alert-success d-flex gap-2 mb-0" role="status">
                                <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong class="d-block">Confirmed</strong>
                                    Bring this reference with you.
                                    @if ($outstanding <= 0)
                                        This reservation is paid in full.
                                    @elseif ($gateways)
                                        You can pay online below, or settle with the resort when you arrive.
                                    @else
                                        Payment is collected by the resort, not through this site.
                                    @endif
                                </div>
                            </div>
                            @break

                        @case(\App\Enums\BookingStatus::Rejected)
                            <div class="alert alert-danger d-flex gap-2 mb-0" role="alert">
                                <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong class="d-block">The resort could not take this booking</strong>
                                    {{ $booking->owner_remarks ?? 'No reason was given.' }}
                                </div>
                            </div>
                            @break

                        @case(\App\Enums\BookingStatus::Cancelled)
                            <div class="alert alert-secondary d-flex gap-2 mb-0" role="status">
                                <i class="bi bi-slash-circle mt-1" aria-hidden="true"></i>
                                <div>
                                    <strong class="d-block">
                                        Cancelled{{ $booking->cancelledBy && ! $booking->cancelledBy->isTourist() ? ' by the resort' : '' }}
                                        @if ($booking->cancelled_at) on {{ $booking->cancelled_at->format('M j, Y') }} @endif
                                    </strong>
                                    {{ $booking->cancellation_reason }}
                                </div>
                            </div>
                            @break

                        @default
                            <div class="alert alert-info d-flex gap-2 mb-0" role="status">
                                <i class="bi bi-check2-all mt-1" aria-hidden="true"></i>
                                <div>This stay is finished. We hope you enjoyed Dasol.</div>
                            </div>
                    @endswitch
                </section>

                {{-- Reviewing is only possible once a stay is finished, which is
                     what makes the ratings on this site worth reading. --}}
                @if ($booking->status === \App\Enums\BookingStatus::Completed)
                    <section class="vd-panel p-4 mb-4" aria-labelledby="review-title">
                        @if ($booking->review)
                            <h2 class="h5 mb-2" id="review-title">Your review</h2>
                            <p class="mb-2"><x-stars :rating="$booking->review->rating" :show-number="false" /></p>

                            @if (filled($booking->review->comment))
                                <p class="text-muted-vd">{{ $booking->review->comment }}</p>
                            @endif

                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('tourist.reviews.edit', $booking) }}">Edit my review</a>
                        @else
                            <h2 class="h5 mb-2" id="review-title">How was your stay?</h2>
                            <p class="text-muted-vd mb-3">
                                Tell other visitors what {{ $resort->name }} was like. It takes a minute.
                            </p>
                            <a class="btn btn-accent"
                               href="{{ route('tourist.reviews.create', $booking) }}">Write a review</a>
                        @endif
                    </section>
                @endif

                {{-- Payment. Offered only once the resort has confirmed: a request
                     it may still decline must not be paid for, because there is
                     nothing here that could send the money back. --}}
                @if ($payable && ($gateways || $manualPayable))
                    <section class="vd-panel p-4 mb-4" aria-labelledby="pay-title">
                        <h2 class="h5 mb-1" id="pay-title">Pay online</h2>
                        <p class="text-muted-vd small mb-3">
                            Paying now is optional. You can still settle with {{ $resort->name }} when you arrive.
                        </p>

                        {{-- What the resort has asked for now, which is not
                             the whole bill wherever a deposit is taken. --}}
                        <div class="vd-pay__due">
                            <span>{{ $terms->balance !== '0.00' ? 'Deposit due now' : 'Amount due' }}</span>
                            <strong>@peso($dueNow, true)</strong>
                        </div>

                        @if ($terms->balance !== '0.00')
                            <p class="form-text mb-3">
                                The remaining @peso($terms->balance, true) is settled with
                                {{ $resort->name }} when you arrive.
                            </p>
                        @endif

                        <div class="vd-pay__choices">
                            @foreach ($gateways as $gateway)
                                <form method="POST" action="{{ route('tourist.payments.start', $booking) }}" data-submit-once>
                                    @csrf
                                    <input type="hidden" name="provider" value="{{ $gateway->value }}">
                                    <button type="submit" class="vd-pay__button">
                                        <span class="vd-pay__name">{{ $gateway->shortLabel() }}</span>
                                        <span class="vd-pay__detail">{{ $gateway->label() }}</span>
                                    </button>
                                </form>
                            @endforeach

                            @if ($manualPayable)
                                <form method="POST" action="{{ route('tourist.manual-payments.start', $booking) }}" data-submit-once>
                                    @csrf
                                    <button type="submit" class="vd-pay__button">
                                        <span class="vd-pay__name">GCash</span>
                                        <span class="vd-pay__detail">GCash (sent to the resort)</span>
                                    </button>
                                </form>
                            @endif
                        </div>

                        {{-- THE CARD FORM, on our own page.

                             Drawn only when a publishable key exists, because
                             without one there is nothing to hand the card to
                             and the buttons above are the whole of it.

                             The number typed here goes from the browser
                             straight to PayMongo. It is not posted to this
                             site, which is why none of these inputs carries a
                             name this server reads. --}}
                        @if ($cardPayable)
                            <details class="vd-card-pay" @if (count($gateways) <= 1 && ! $manualPayable) open @endif>
                                <summary class="vd-card-pay__summary">
                                    <i class="bi bi-credit-card-2-front me-1" aria-hidden="true"></i>
                                    Pay by card here
                                </summary>

                                <form class="vd-card-pay__form"
                                      method="POST"
                                      action="{{ route('tourist.payments.card.start', $booking) }}"
                                      data-card-form
                                      autocomplete="on">
                                    @csrf

                                    <p class="alert alert-danger" role="alert" data-card-error hidden></p>

                                    <label class="form-label" for="card_name">Card holder name</label>
                                    <input class="form-control mb-3" id="card_name" name="card_name"
                                           type="text" required autocomplete="cc-name"
                                           placeholder="Name as printed on the card">

                                    <label class="form-label" for="card_number">Card number</label>
                                    <input class="form-control mb-3" id="card_number" name="card_number"
                                           type="text" required inputmode="numeric" autocomplete="cc-number"
                                           maxlength="23" placeholder="0000 0000 0000 0000">

                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label" for="card_expiry">Expiry</label>
                                            <input class="form-control" id="card_expiry" name="card_expiry"
                                                   type="text" required inputmode="numeric"
                                                   autocomplete="cc-exp" maxlength="7" placeholder="MM/YY">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="card_cvc">CVC</label>
                                            <input class="form-control" id="card_cvc" name="card_cvc"
                                                   type="text" required inputmode="numeric"
                                                   autocomplete="cc-csc" maxlength="4" placeholder="000">
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-accent btn-lg w-100"
                                            data-card-submit data-busy="Contacting your bank…">
                                        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
                                        Pay @peso($dueNow, true)
                                    </button>

                                    <p class="form-text text-center mb-0 mt-2">
                                        Your card goes straight to PayMongo. Your bank may ask you to
                                        confirm before the payment completes.
                                    </p>
                                </form>
                            </details>
                        @endif

                        <p class="form-text mb-0">
                            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                            Your card details are sent straight to PayMongo and are never stored by,
                            or even seen by, this site.
                        </p>
                    </section>
                @elseif ($booking->payment_preference === \App\Enums\PaymentPreference::Online
                         && $booking->status === \App\Enums\BookingStatus::Pending)
                    {{-- They chose QRPh on the booking form, so the absence of a
                         pay button here needs explaining. Without this the page
                         simply looks as though their choice was ignored. --}}
                    <section class="vd-panel p-4 mb-4" aria-labelledby="pay-soon-title">
                        <h2 class="h5 mb-1" id="pay-soon-title">Paying by {{ \App\Enums\PaymentProvider::payMongoMethodPhrase() }}</h2>
                        <p class="text-muted-vd mb-0">
                            You chose to pay by {{ \App\Enums\PaymentProvider::payMongoMethodPhrase() }}. The payment button appears here as soon as
                            {{ $resort->name }} confirms your request &mdash; nothing is charged
                            while they are still deciding.
                        </p>
                    </section>
                @endif

                {{-- The record of what has actually been paid, whatever the
                     reservation's own status. --}}
                @if ($booking->payments->isNotEmpty())
                    <section class="vd-panel p-4 mb-4" aria-labelledby="paid-title">
                        <h2 class="h5 mb-3" id="paid-title">Payments</h2>

                        <ul class="list-unstyled mb-0">
                            @foreach ($booking->payments as $payment)
                                <li class="d-flex flex-wrap justify-content-between gap-2 border-top py-2">
                                    <span>
                                        <span class="d-block fw-semibold">@peso($payment->amount)</span>
                                        <span class="small text-muted-vd font-monospace">{{ $payment->payment_reference }}</span>
                                    </span>
                                    <span class="text-end">
                                        <x-status-badge :status="$payment->status" />
                                        <span class="d-block small text-muted-vd">
                                            {{ $payment->method ? Str::headline($payment->method) : $payment->provider->shortLabel() }}
                                            @if ($payment->paid_at)
                                                &middot; {{ $payment->paid_at->format('M j, Y g:i A') }}
                                            @endif
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">Your stay</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted-vd fw-normal">Resort</dt>
                        <dd class="col-sm-8">
                            @if ($resort->isPubliclyVisible())
                                <a href="{{ route('resorts.show', $resort) }}">{{ $resort->name }}</a>
                            @else
                                {{ $resort->name }}
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted-vd fw-normal">Room type</dt>
                        <dd class="col-sm-8">{{ $booking->rooms_booked }} x {{ $booking->room?->name }}</dd>

                        <x-stay-dates :booking="$booking" />

                        <dt class="col-sm-4 text-muted-vd fw-normal">Guests</dt>
                        <dd class="col-sm-8"><x-guest-party :booking="$booking" /></dd>

                        <dt class="col-sm-4 text-muted-vd fw-normal">Requested on</dt>
                        <dd class="col-sm-8 mb-0">{{ $booking->created_at->format('M j, Y g:i A') }}</dd>
                    </dl>

                    @if (filled($booking->special_requests))
                        <div class="border-top mt-3 pt-3">
                            <strong class="d-block">What you asked for</strong>
                            <span class="text-muted-vd">{{ $booking->special_requests }}</span>
                        </div>
                    @endif
                </section>

                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">Price</h2>

                    <x-price-breakdown :booking="$booking" class="mb-2" />
                </section>

                {{-- What was asked for besides the room, and what the resort
                     said. Its own panel, below the price: these are settled
                     at the resort and are no part of the figures above. --}}
                <x-booking-extras :booking="$booking" />

                {{-- The terms this reservation was made under, read back
                     from its own snapshot. A resort that changes its
                     deposit next month does not change what was agreed
                     here, and this is where a guest can check that. --}}
                <section class="vd-panel p-4 mt-4">
                    <h2 class="h5 mb-3">Payment</h2>

                    <x-payment-terms :terms="$terms" :paid="$paid"
                                     heading="What you agreed to pay" />

                    @if ($booking->payment_preference)
                        <p class="form-text mb-0 mt-3">
                            <i class="bi bi-{{ $booking->payment_preference->icon() }} me-1" aria-hidden="true"></i>
                            You chose: {{ $booking->payment_preference->label() }}.
                            {{ $booking->payment_preference->description() }}
                        </p>
                    @endif

                    {{-- Said plainly, because a confirmed booking looks
                         settled and is not. The resort marks a payment
                         received; nothing here does it automatically. --}}
                    @if ($dueNow > 0 && $booking->status === \App\Enums\BookingStatus::Confirmed)
                        <p class="form-text mb-0 mt-2">
                            <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
                            Your booking is confirmed, but
                            <strong>@peso($dueNow, true)</strong> has not been paid yet.
                        </p>
                    @endif
                </section>

                {{-- The valid ID the resort checks at the desk.
                     Below the payment panel, because it is the other thing
                     a guest has to do before they travel, and because a
                     rejected ID is the one that will stop them at the gate. --}}
                <section class="vd-panel p-4 mt-4" aria-labelledby="valid-id-title">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <h2 class="h5 mb-0" id="valid-id-title">Your valid ID</h2>
                        <x-status-badge :status="$booking->id_document_status" />
                    </div>

                    @if ($booking->idIsVerified())
                        <p class="mb-0">
                            {{ $booking->resort?->name ?? 'The resort' }} has verified your ID.
                            Nothing else is needed — just bring the same ID with you, as they will
                            ask to see it when you arrive.
                        </p>
                    @else
                        @if ($booking->id_document_status === \App\Enums\IdVerificationStatus::Pending)
                            <p class="text-muted-vd">
                                Your ID is with the resort and is waiting to be checked.
                                You do not need to do anything else for now.
                            </p>
                        @elseif (filled($booking->id_document_rejection_reason))
                            <div class="alert alert-warning small" role="status">
                                <strong>The resort could not accept the ID you sent.</strong>
                                <div class="mt-1">{{ $booking->id_document_rejection_reason }}</div>
                                <div class="mt-1">Please send another government-issued ID below.</div>
                            </div>
                        @else
                            <p class="text-muted-vd">
                                The resort checks a government-issued ID at the desk.
                                Sending it now means they can verify it before you travel.
                            </p>
                        @endif

                        @if ($booking->hasIdDocument())
                            <x-external-link :href="route('tourist.bookings.id-document.show', $booking)"
                                             class="btn btn-sm btn-light border mb-3">
                                <i class="bi bi-file-earmark-person me-1" aria-hidden="true"></i>
                                See what you sent
                            </x-external-link>
                        @endif

                        @can('uploadId', $booking)
                            <form method="POST" action="{{ route('tourist.bookings.id-document.store', $booking) }}"
                                  enctype="multipart/form-data">
                                @csrf

                                <label class="form-label" for="id_document">
                                    {{ $booking->hasIdDocument() ? 'Send another ID' : 'Upload your valid ID' }}
                                </label>

                                <input class="form-control @error('id_document') is-invalid @enderror"
                                       type="file" id="id_document" name="id_document"
                                       accept=".jpg,.jpeg,.png,.pdf" required>

                                @error('id_document')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror

                                <p class="form-text">
                                    A photo or PDF of a government-issued ID — passport, driver's licence,
                                    PhilID, postal ID. JPG, PNG or PDF, up to
                                    {{ round(config('visitdasol.uploads.document_max_kb') / 1024, 1) }} MB.
                                    Only this resort can open it, and it is never published.
                                </p>

                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload me-1" aria-hidden="true"></i>
                                    Send to the resort
                                </button>
                            </form>
                        @endcan
                    @endif
                </section>

            </div>

            <div class="col-lg-5">
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">Contact the resort</h2>
                    <dl class="row mb-3 small">
                        <dt class="col-5 text-muted-vd fw-normal">Phone</dt>
                        <dd class="col-7"><a href="tel:{{ $resort->contact_number }}">{{ $resort->contact_number }}</a></dd>

                        @if (filled($resort->email))
                            <dt class="col-5 text-muted-vd fw-normal">Email</dt>
                            <dd class="col-7 text-break"><a href="mailto:{{ $resort->email }}">{{ $resort->email }}</a></dd>
                        @endif

                        <dt class="col-5 text-muted-vd fw-normal">Address</dt>
                        <dd class="col-7 mb-0">{{ $resort->full_address }}</dd>
                    </dl>

                    <x-location-map :latitude="$resort->latitude" :longitude="$resort->longitude"
                                    :label="$resort->name" style="height: 13rem" class="mb-2" />

                    <x-external-link class="btn btn-outline-primary btn-sm"
                       href="https://www.openstreetmap.org/?mlat={{ $resort->latitude }}&mlon={{ $resort->longitude }}#map=16/{{ $resort->latitude }}/{{ $resort->longitude }}">
                        <i class="bi bi-compass me-1" aria-hidden="true"></i>Get directions
                    </x-external-link>
                </aside>

                <aside class="vd-panel p-4">
                    <h2 class="h5 mb-3">Change of plans?</h2>

                    {{-- Moving the dates, offered only while the resort is
                         still deciding. Above cancelling on purpose: somebody
                         whose plans shifted by a day wants this, and should
                         meet it before they meet the button that throws the
                         whole reservation away. --}}
                    @can('reschedule', $booking)
                        <p class="text-muted-vd">
                            {{ $resort->name }} has not answered yet, so you can still change your dates.
                            The amount is worked out again for the new dates, and nothing is charged.
                        </p>

                        <x-confirm-form id="reschedule-booking"
                                        :action="route('tourist.bookings.reschedule', $booking)"
                                        title="Change your dates?"
                                        message="The resort will be told about the new dates before they answer. The rooms must be free on those dates."
                                        trigger="Change my dates"
                                        confirm="Change the dates"
                                        icon="calendar-event"
                                        variant="primary"
                                        trigger-class="btn btn-outline-primary">
                            <div class="row g-2 mt-2 text-start">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="reschedule_check_in">
                                        New arrival date
                                    </label>
                                    <input type="date" class="form-control" id="reschedule_check_in"
                                           name="check_in_date" required
                                           min="{{ \Carbon\CarbonImmutable::today()->toDateString() }}"
                                           value="{{ old('check_in_date', $booking->check_in_date->toDateString()) }}">
                                </div>

                                {{-- A tour is one date. Asking for a departure
                                     would promise a second night nobody sold. --}}
                                @if ($booking->booking_type->isOvernight())
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label" for="reschedule_check_out">
                                            New departure date
                                        </label>
                                        <input type="date" class="form-control" id="reschedule_check_out"
                                               name="check_out_date" required
                                               min="{{ \Carbon\CarbonImmutable::today()->addDay()->toDateString() }}"
                                               value="{{ old('check_out_date', $booking->check_out_date->toDateString()) }}">
                                    </div>
                                @endif
                            </div>

                            <p class="form-text mb-0 mt-2 text-start">
                                Your room and the size of your party stay the same. For a different room,
                                cancel and book again.
                            </p>
                        </x-confirm-form>

                        <hr class="my-4">
                    @endcan

                    @can('cancelAsTourist', $booking)
                        <p class="text-muted-vd">
                            You can cancel until the day before check-in. The rooms go back to other guests straight away,
                            so please cancel as soon as you know.
                        </p>

                        <x-confirm-form id="cancel-booking"
                                        :action="route('tourist.bookings.cancel', $booking)"
                                        title="Cancel this reservation?"
                                        message="This cannot be undone. You would need to book again, and the rooms may be taken by then."
                                        trigger="Cancel this reservation"
                                        confirm="Yes, cancel it"
                                        icon="x-circle"
                                        variant="danger"
                                        trigger-class="btn btn-outline-danger">
                            <div class="mt-3">
                                <label class="form-label" for="cancellation_reason">
                                    Reason <span class="text-muted-vd fw-normal small">(optional, the resort sees this)</span>
                                </label>
                                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason"
                                          rows="2" maxlength="500">{{ old('cancellation_reason') }}</textarea>
                            </div>
                        </x-confirm-form>
                    @elseif (in_array($booking->status, [\App\Enums\BookingStatus::Pending, \App\Enums\BookingStatus::Confirmed], true))
                        <p class="text-muted-vd mb-0">
                            It is too close to your arrival to cancel here. Please call {{ $resort->name }}
                            on <a href="tel:{{ $resort->contact_number }}">{{ $resort->contact_number }}</a>.
                        </p>
                    @else
                        <p class="text-muted-vd mb-0">
                            This reservation is closed. To stay at {{ $resort->name }}, make a new request.
                        </p>
                    @endcan
                </aside>
            </div>
        </div>
    </div>
@endsection
