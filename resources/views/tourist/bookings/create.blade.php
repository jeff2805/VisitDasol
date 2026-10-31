@extends('layouts.public')

@section('title', 'Request a room')

@push('scripts')
    <script src="@vdasset('js/booking-form.js')"></script>
@endpush

@section('content')
    <div class="container py-5">
        @if ($nights < 1)
            <x-page-header title="Request a room" :subtitle="$resort->name" />

            <div class="alert alert-warning" role="status">
                Choose your dates on the resort page first.
                <a href="{{ route('resorts.show', $resort) }}">Go back to {{ $resort->name }}</a>.
            </div>
        @else
            @php
                $guestName = auth()->user();
            @endphp

            <div class="vd-booking" data-booking-form>
                {{-- Who and where, the way the resort's own page shows it. --}}
                <header class="vd-booking__head">
                    <img class="vd-booking__thumb" src="{{ $resort->main_image_url }}" alt="" width="96" height="72">

                    <div class="min-w-0">
                        <h1 class="vd-booking__title">{{ $resort->name }}</h1>
                        <p class="vd-booking__where mb-0">
                            <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                            Brgy. {{ $resort->barangay }}, {{ $resort->municipality }}
                        </p>
                    </div>

                    <a class="btn btn-sm btn-outline-primary ms-auto flex-shrink-0"
                       href="{{ route('resorts.show', $resort) }}">Back to resort</a>
                </header>

                <form method="POST" action="{{ route('tourist.bookings.store') }}"
                      enctype="multipart/form-data" novalidate data-submit-once
                      {{-- The route is declared inside a group with ->name('tourist.'),
                           so its full name carries that prefix. Without it route()
                           threw "Route [bookings.payment-terms-preview] not defined"
                           and the whole booking form was a 500 — no guest without an
                           existing reservation could book at all. --}}
                      data-payment-terms-preview-url="{{ route('tourist.bookings.payment-terms-preview') }}">
                    @csrf
                    <input type="hidden" name="room" value="{{ $room->id }}" data-booking-room-id>

                    <div class="row g-4">
                        <div class="col-lg-7">
                            <section class="vd-panel p-4 mb-2">
                                <h2 class="h6 vd-booking__legend">Who is staying</h2>

                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="contact_first_name" label="First name" required
                                                      autocomplete="given-name"
                                                      :value="old('contact_first_name', $guestName->first_name)" />
                                    </div>
                                    <div class="col-sm-6">
                                        <x-form.input name="contact_last_name" label="Last name" required
                                                      autocomplete="family-name"
                                                      :value="old('contact_last_name', $guestName->last_name)" />
                                    </div>
                                </div>

                                {{-- The two fields above are joined into one stored name, so an
                                     error on that joined value needs somewhere to appear. --}}
                                @error('contact_name')
                                    <p class="text-danger small mt-n2 mb-3">{{ $message }}</p>
                                @enderror

                                <x-form.input name="contact_email" type="email" label="Email address" required
                                              autocomplete="email"
                                              :value="old('contact_email', $guestName->email)"
                                              help="Most resorts reply here first." />

                                <x-form.input name="contact_phone" type="tel" label="Contact number" required
                                              autocomplete="tel"
                                              :value="old('contact_phone', $guestName->phone)" />
                            </section>

                            <section class="vd-panel p-4">
                                <h2 class="h6 vd-booking__legend">Your stay</h2>

                                {{-- Only the kinds that are actually sold here. A
                                     resort that has set no day-tour price does
                                     not run them, and offering the choice would
                                     promise something the next screen has to
                                     refuse.

                                     Built from every room in the picker below,
                                     not only the one the page opened on, because
                                     the guest can change room without leaving the
                                     form. The script then hides the kinds the
                                     chosen room does not run. --}}
                                @php($types = collect(\App\Enums\BookingType::cases())
                                        ->filter(fn ($case) => $rooms->contains(fn ($option) => $option->offers($case)))
                                        ->values())

                                @if ($types->count() > 1)
                                    <fieldset class="mb-3" data-booking-types>
                                        <legend class="form-label">What are you booking?</legend>

                                        @foreach ($types as $case)
                                            <label class="vd-paychoice__option"
                                                   data-booking-type-option="{{ $case->value }}"
                                                   @unless ($room->offers($case)) hidden @endunless>
                                                <input class="form-check-input" type="radio"
                                                       name="booking_type" value="{{ $case->value }}"
                                                       data-booking-type
                                                       {{-- The hours this kind keeps. A day group
                                                            handed the resort's 2 p.m. check-in
                                                            would miss most of the day. --}}
                                                       data-hours-in="{{ $case->defaultHours()[0] }}"
                                                       data-hours-out="{{ $case->defaultHours()[1] }}"
                                                       @disabled(! $room->offers($case))
                                                       @checked(old('booking_type', 'overnight') === $case->value)>
                                                <span class="vd-paychoice__text">
                                                    <span class="vd-paychoice__name">
                                                        <i class="bi bi-{{ $case->icon() }}" aria-hidden="true"></i>
                                                        {{ $case->label() }}
                                                        {{-- Rewritten when the room changes, so the
                                                             rate beside the name is always the rate
                                                             for the room in the picker. --}}
                                                        <span class="text-muted-vd fw-normal" data-booking-type-price>
                                                            @if ($room->offers($case))
                                                                — @peso($room->priceFor($case))@if ($case->isOvernight()) per night @endif
                                                            @endif
                                                        </span>
                                                    </span>
                                                    <span class="vd-paychoice__detail">{{ $case->description() }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </fieldset>

                                    @error('booking_type')
                                        <p class="text-danger small">{{ $message }}</p>
                                    @enderror
                                @else
                                    <input type="hidden" name="booking_type" value="overnight">
                                @endif

                                <div class="row">
                                    <div class="col-sm-6">
                                        {{-- The label changes for a tour: there is
                                             no "check-in" to a day at the beach. --}}
                                        <x-form.input name="check_in_date" type="date" label="Check-in" required
                                                      :value="old('check_in_date', $checkIn->toDateString())"
                                                      min="{{ now()->toDateString() }}"
                                                      data-booking-check-in />
                                    </div>
                                    {{-- A tour is one date, so this is folded away
                                         for one. The server derives the day after
                                         itself; it is never asked of the guest. --}}
                                    <div class="col-sm-6" data-booking-checkout-field
                                         @if (old('booking_type', 'overnight') !== 'overnight') hidden @endif>
                                        <x-form.input name="check_out_date" type="date" label="Check-out" required
                                                      :value="old('check_out_date', $checkOut->toDateString())"
                                                      min="{{ now()->addDay()->toDateString() }}"
                                                      data-booking-check-out />
                                    </div>
                                </div>

                                {{-- Guest-selected check-in and check-out times. --}}
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <label for="check_in_time" class="form-label">Check-in time <span class="text-danger">*</span></label>
                                        <input
                                            type="time"
                                            id="check_in_time"
                                            name="check_in_time"
                                            class="form-control @error('check_in_time') is-invalid @enderror"
                                            value="{{ old('check_in_time', $checkInTime ?? '14:00') }}"
                                            data-booking-time-in
                                            required
                                        >
                                        @error('check_in_time')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="check_out_time" class="form-label">Check-out time <span class="text-danger">*</span></label>
                                        <input
                                            type="time"
                                            id="check_out_time"
                                            name="check_out_time"
                                            class="form-control @error('check_out_time') is-invalid @enderror"
                                            value="{{ old('check_out_time', $checkOutTime ?? '12:00') }}"
                                            data-booking-time-out
                                            required
                                        >
                                        @error('check_out_time')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                {{-- What else the resort offers.

                                     ASKED FOR, NOT BOUGHT. Whether a boat goes
                                     out depends on the weather and the boatman,
                                     so the resort answers each one — and none of
                                     it is added to the total below, because
                                     these are settled at the resort. Saying both
                                     here is the difference between a guest who
                                     turns up expecting a kayak and one who
                                     knows to ask again. --}}
                                @if ($extras->isNotEmpty())
                                    <fieldset class="vd-extras mb-3">
                                        <legend class="form-label">Anything else? <span class="text-muted-vd fw-normal small">(optional)</span></legend>

                                        <p class="form-text mt-0 mb-2">
                                            Tick what you would like. The resort confirms each one,
                                            and you pay for them there &mdash; they are not part of
                                            the total on the right.
                                        </p>

                                        @foreach ($extras as $extra)
                                            {{-- What they actually typed, kept exactly.
                                                 This used to be (int) and then blanked
                                                 unless it was above zero, so a guest who
                                                 typed 0 got the box back empty — the
                                                 answer that caused the error erased
                                                 itself, and they had nothing to correct. --}}
                                            @php($asked = old('extras.'.$extra->id))
                                            <div class="vd-extras__item">
                                                <span class="vd-extras__what">
                                                    <span class="d-block fw-semibold">{{ $extra->name }}</span>
                                                    <span class="small text-muted-vd">
                                                        @peso($extra->price) {{ $extra->price_unit->suffix() }}
                                                    </span>
                                                    @if (filled($extra->description))
                                                        <span class="d-block small text-muted-vd">{{ $extra->description }}</span>
                                                    @endif
                                                </span>

                                                <span class="vd-extras__count">
                                                    <label class="form-label small mb-1"
                                                           for="field-extra-{{ $extra->id }}">
                                                        {{ $extra->price_unit->quantityLabel() }}
                                                    </label>
                                                    <input type="number"
                                                           class="form-control form-control-sm @error('extras.'.$extra->id) is-invalid @enderror"
                                                           id="field-extra-{{ $extra->id }}"
                                                           name="extras[{{ $extra->id }}]"
                                                           min="0" max="{{ $extra->max_quantity }}" step="1"
                                                           value="{{ $asked }}"
                                                           placeholder="0">
                                                    {{-- The error for THIS box. Laravel files it
                                                         under "extras.7", and $errors->has('extras')
                                                         is false for that, so the one message below
                                                         the fieldset could never fire: the banner
                                                         counted a field nothing on the page marked. --}}
                                                    @error('extras.'.$extra->id)
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </span>
                                            </div>
                                        @endforeach

                                        @error('extras')
                                            <p class="text-danger small mb-0">{{ $message }}</p>
                                        @enderror
                                    </fieldset>
                                @endif

                                <div class="mb-3">
                                    <label class="form-label" for="field-room-type">Room type</label>
                                    <select class="form-select" id="field-room-type" data-booking-room>
                                        {{-- Each room carries its own rate, capacity, beds and
                                             discount policy, so switching room type redraws the
                                             whole summary without going back to the server. --}}
                                        @foreach ($rooms as $option)
                                            <option value="{{ $option->id }}"
                                                    data-price="{{ $option->price_per_night }}"
                                                    data-day-tour-price="{{ $option->priceFor(\App\Enums\BookingType::DayTour) }}"
                                                    data-night-tour-price="{{ $option->priceFor(\App\Enums\BookingType::NightTour) }}"
                                                    data-capacity="{{ $option->capacity }}"
                                                    data-beds="{{ $option->bed_count }}"
                                                    data-child-percent="{{ $option->hasChildDiscount() ? $option->child_discount_percent : 0 }}"
                                                    data-child-ages="{{ $option->hasChildDiscount() ? $option->childAgeRange() : '' }}"
                                                    data-pwd-percent="{{ $option->hasPwdDiscount() ? $option->pwd_discount_percent : 0 }}"
                                                    @selected($option->id === $room->id)>
                                                {{ $option->name }} — @peso($option->price_per_night) per night
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="form-text" data-booking-room-note hidden>
                                        Whether this room type is free for your dates is checked when you send the request.
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="rooms_booked" type="number" label="Rooms" required min="1"
                                                      max="{{ max(1, (int) $available) }}"
                                                      :value="old('rooms_booked', $roomsBooked)"
                                                      data-booking-rooms />
                                        <p class="form-text mt-n2" data-booking-free>{{ $available }} free for these dates.</p>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="field-adults" class="form-label">Adults <span class="text-danger">*</span></label>
                                        <input id="field-adults" type="number" name="adults"
                                               class="form-control @error('adults') is-invalid @enderror"
                                               min="1" max="60" required
                                               value="{{ old('adults', max(1, (int) $guests)) }}"
                                               data-booking-adults>
                                        @error('adults')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="field-children" class="form-label">
                                            Children <span data-booking-child-ages>{{ $room->hasChildDiscount() ? '(' . $room->childAgeRange() . ')' : '' }}</span>
                                        </label>
                                        <input id="field-children" type="number" name="children"
                                               class="form-control @error('children') is-invalid @enderror"
                                               min="0" max="59" required
                                               value="{{ old('children', 0) }}"
                                               data-booking-children>
                                        @error('children')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        {{-- The room's own ages, because the resort checks them at
                                             the gate and a guest counting to a different definition
                                             is a guest who gets a different bill on arrival. --}}
                                        <div class="form-text" data-booking-child-note></div>
                                    </div>
                                    <div class="col-sm-6 mt-3">
                                        <label for="field-pwd-guests" class="form-label">PWD guests</label>
                                        <input id="field-pwd-guests" type="number" name="pwd_guests"
                                               class="form-control @error('pwd_guests') is-invalid @enderror"
                                               min="0" max="60" required
                                               value="{{ old('pwd_guests', 0) }}"
                                               data-booking-pwd>
                                        @error('pwd_guests')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text">
                                            How many of the adults and children above have a PWD ID.
                                            They are not extra guests, so do not count them twice.
                                            <span data-booking-pwd-note></span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 mt-3">
                                        <label for="field-guests-total" class="form-label">Total guests</label>
                                        <input id="field-guests-total" type="number" name="guests"
                                               class="form-control" min="1" max="60" required readonly
                                               value="{{ old('guests', $guests) }}"
                                               data-booking-guests>
                                        <p class="form-text mt-n2" data-booking-capacity>
                                            Up to {{ $room->capacity }} per room.
                                        </p>
                                    </div>
                                </div>

                                {{-- What the Municipal Tourism Office has to
                                     report to the Department of Tourism every
                                     month, and used to count by hand from each
                                     resort's guest register.

                                     Asked in the open and required. Folded away
                                     and optional at first, which is the same as
                                     not asking: almost nobody opens an optional
                                     question, and the office would be filing a
                                     return of blanks. --}}
                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="guests_male" type="number" label="Male" required
                                                      min="0" max="60"
                                                      :value="old('guests_male')" data-booking-male />
                                    </div>
                                    <div class="col-sm-6">
                                        <x-form.input name="guests_female" type="number" label="Female" required
                                                      min="0" max="60"
                                                      :value="old('guests_female')" data-booking-female />
                                    </div>
                                </div>

                                {{-- How the two compare with the number of guests
                                     above. The form refuses a split that does not
                                     add up, and being told that only after
                                     sending the request is a wasted journey. --}}
                                <p class="form-text mt-n2 mb-3" data-booking-sex role="status">
                                    Split your guests between the two. Put 0 where there are none.
                                </p>

                                <div class="mb-3">
                                    <label class="form-label" for="field-origin">Travelling from</label>
                                    <select class="form-select @error('origin') is-invalid @enderror"
                                            id="field-origin" name="origin" required>
                                        {{-- Empty and disabled rather than a
                                             "rather not say": the menu has to
                                             start on something, and an option
                                             that opts out is the one everybody
                                             would pick. --}}
                                        <option value="" disabled @selected(! old('origin'))>Choose one</option>
                                        @foreach (\App\Enums\VisitorOrigin::cases() as $case)
                                            <option value="{{ $case->value }}" @selected(old('origin') === $case->value)>
                                                {{ $case->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('origin')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">
                                        Dasol reports its visitor numbers to the Department of Tourism each month.
                                        These three answers are never shown publicly and never reach the resort.
                                    </div>
                                </div>

                                <div class="mb-0">
                                    <label class="form-label" for="field-special-requests">
                                        Special requests <span class="text-muted-vd fw-normal small">(optional)</span>
                                    </label>
                                    <textarea class="form-control @error('special_requests') is-invalid @enderror"
                                              id="field-special-requests" name="special_requests" rows="3"
                                              placeholder="E.g. early check-in, extra beds, dietary needs.">{{ old('special_requests') }}</textarea>
                                    @error('special_requests')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </section>
                        </div>

                        <div class="col-lg-5">
                            <aside class="vd-panel p-4 vd-booking__summary">
                                <h2 class="h6 vd-booking__legend">Booking summary</h2>

                                <img class="vd-booking__room-photo"
                                     src="{{ $room->images->first()?->url ?? $resort->main_image_url }}" alt="">

                                <dl class="vd-booking__lines">
                                    <dt>Room type</dt>
                                    <dd data-booking-room-name>{{ $room->name }}</dd>

                                    <dt>Beds</dt>
                                    <dd data-booking-beds>{{ $room->bed_count * $roomsBooked }}</dd>

                                    <dt>Guest capacity</dt>
                                    <dd data-booking-capacity-summary>{{ $room->capacity }} guests per room</dd>

                                    @if ($types->count() > 1)
                                        <dt>Booking</dt>
                                        <dd data-booking-type-name>Overnight stay</dd>
                                    @endif

                                    <dt>Dates</dt>
                                    <dd data-booking-dates>{{ $checkIn->format('M j') }} to {{ $checkOut->format('M j, Y') }}</dd>

                                    {{-- Dropped for a tour. Nobody sleeps on one,
                                         and a line reading "0 nights" looks like
                                         something went wrong with the booking. --}}
                                    <dt data-booking-nights-term>Nights</dt>
                                    <dd data-booking-nights-value><span data-booking-nights>{{ $nights }}</span></dd>

                                    <dt>Check-in time</dt>
                                    <dd data-booking-check-in-time>{{ \Illuminate\Support\Carbon::parse(old('check_in_time', $checkInTime ?? ($resort->check_in_time ?? '14:00')))->format('g:i A') }}</dd>

                                    <dt>Check-out time</dt>
                                    <dd data-booking-check-out-time>{{ \Illuminate\Support\Carbon::parse(old('check_out_time', $checkOutTime ?? ($resort->check_out_time ?? '12:00')))->format('g:i A') }}</dd>
                                </dl>

                                <div class="vd-booking__totals">
                                    <div class="vd-booking__row">
                                        <span data-booking-working>
                                            @peso($room->price_per_night) x {{ $nights }} {{ Str::plural('night', $nights) }} x {{ $roomsBooked }} {{ Str::plural('room', $roomsBooked) }}
                                        </span>
                                        <span data-booking-subtotal>@peso($price->subtotal, true)</span>
                                    </div>

                                    {{-- A discount line only appears once there is one. A row
                                         reading "Child discount ₱0.00" beside a party with no
                                         children tells a guest nothing and reads like a refusal. --}}
                                    <div class="vd-booking__row" data-booking-child-row
                                         @if ($price->childDiscount === '0.00') hidden @endif>
                                        <span data-booking-child-label>
                                            Child discount
                                            @if ($price->childPercent > 0)
                                                ({{ $price->childPercent }}% off {{ $price->childrenDiscounted }} {{ Str::plural('guest', $price->childrenDiscounted) }})
                                            @endif
                                        </span>
                                        <span data-booking-child-discount>−@peso($price->childDiscount, true)</span>
                                    </div>

                                    <div class="vd-booking__row" data-booking-pwd-row
                                         @if ($price->pwdDiscount === '0.00') hidden @endif>
                                        <span data-booking-pwd-label>
                                            PWD discount
                                            @if ($price->pwdPercent > 0)
                                                ({{ $price->pwdPercent }}% off {{ $price->pwdDiscounted }} {{ Str::plural('guest', $price->pwdDiscounted) }})
                                            @endif
                                        </span>
                                        <span data-booking-pwd-discount>−@peso($price->pwdDiscount, true)</span>
                                    </div>

                                    <div class="vd-booking__row vd-booking__row--total">
                                        <span>Total</span>
                                        <strong data-booking-total
                                                data-booking-total-amount="{{ $price->total }}">@peso($price->total, true)</strong>
                                    </div>
                                    <p class="form-text mt-2 mb-0" data-booking-discount-note></p>
                                </div>

                                {{-- What this resort asks for up front, before the
                                     guest commits to anything. Worked out on the
                                     server from the resort's own terms, and worked
                                     out again when the request is saved, so a page
                                     left open overnight cannot lock in a stale quote.

                                     The figures move with the party and the dates,
                                     which the browser changes above, so this is the
                                     terms rather than the arithmetic: the exact
                                     amounts are confirmed on the next screen. --}}
                                <div class="mt-3" data-payment-terms-preview aria-live="polite"
                                     aria-busy="false">
                                    <x-payment-terms :terms="$terms"
                                                     heading="How this resort takes payment" />
                                </div>

                                {{-- How the guest means to pay. A choice, not a charge: the
                                     resort can still decline this request, and there is nothing
                                     here that could send money back, so QRPh is offered as the
                                     next step rather than as a condition of asking. Hidden
                                     entirely when no gateway is configured, so the form never
                                     promises a payment link the system cannot produce. --}}

{{-- Payment preference options --}}
@if (count($preferences) > 1)
    <fieldset class="vd-paychoice">
        <legend class="vd-paychoice__legend">
            How would you like to pay?
        </legend>

        @foreach ($preferences as $preference)
            <label class="vd-paychoice__option">
                <input
                    class="form-check-input"
                    type="radio"
                    name="payment_preference"
                    value="{{ $preference->value }}"
                    data-payment-preference
                    @checked(
                        old(
                            'payment_preference',
                            \App\Enums\PaymentPreference::Online->value
                        ) === $preference->value
                    )
                >

                <span class="vd-paychoice__text">
                    <span class="vd-paychoice__name">
                        <i
                            class="bi bi-{{ $preference->icon() }}"
                            aria-hidden="true"
                        ></i>
                        {{ $preference->label() }}
                    </span>

                    <span class="vd-paychoice__detail">
                        {{ $preference->description() }}
                    </span>
                </span>
            </label>
        @endforeach
    </fieldset>

    @error('payment_preference')
        <p class="text-danger small mb-0 mt-2">
            {{ $message }}
        </p>
    @enderror
@endif

{{-- Manual GCash details --}}
@if (isset($manualGcashAccount) && $manualGcashAccount)
    <section
        class="vd-panel p-3 mt-3"
        data-manual-gcash-details
        hidden
    >
        
<div class="mt-4">
    <label for="gcash_reference_number" class="form-label fw-semibold">
        GCash Reference Number <span class="text-danger">*</span>
    </label>

    <input
        type="text"
        id="gcash_reference_number"
        name="gcash_reference_number"
        class="form-control"
        value="{{ old('gcash_reference_number') }}"
        placeholder="Enter your GCash reference number"
        maxlength="100"
        autocomplete="off"
    >

    @error('gcash_reference_number')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</div>

<div class="mt-3">
    <label for="payment_receipt" class="form-label fw-semibold">
        Upload GCash Receipt <span class="text-danger">*</span>
    </label>

    <input
        type="file"
        id="payment_receipt"
        name="payment_receipt"
        class="form-control"
        accept="image/jpeg,image/png,image/webp"
    >

    <small class="text-muted d-block mt-1">
        Upload a clear screenshot or photo of your GCash receipt.
        Accepted formats: JPG, PNG, or WEBP. Maximum size: 5 MB.
    </small>

    @error('payment_receipt')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</div>

        <h3 class="h6 mb-3">
            <i class="bi bi-qr-code me-1" aria-hidden="true"></i>
            Resort GCash payment details
        </h3>

        <p class="mb-1">
            <strong>Account name:</strong>
            {{ $manualGcashAccount->account_name }}
        </p>

        <p class="mb-2">
            <strong>GCash number:</strong>
            {{ $manualGcashAccount->gcash_number }}
        </p>

        @if ($manualGcashAccount->qr_image)
            <div class="mt-3">
                <p class="fw-semibold mb-2">
                    Scan GCash QR code
                </p>

                <img
                    src="{{ asset('storage/' . $manualGcashAccount->qr_image) }}"
                    alt="GCash QR code for {{ $resort->name }}"
                    loading="lazy"
                    style="display:block;max-width:240px;width:100%;height:auto"
                >
            </div>
        @else
            <p class="small text-muted mt-2 mb-0">
                No GCash QR code has been uploaded yet.
                You can use the GCash number above.
            </p>
        @endif

        @if ($manualGcashAccount->instructions)
            <p class="mt-3 mb-0">
                {{ $manualGcashAccount->instructions }}
            </p>
        @endif

        <p class="small text-muted mt-3 mb-0">
            Submit your reservation first. Follow the resort's
            payment instructions after your booking is confirmed.
        </p>
    </section>
@endif


                                <button type="submit" class="btn btn-accent btn-lg w-100 mt-3">
                                    <i class="bi bi-check2" aria-hidden="true"></i> Confirm reservation
                                </button>

                                {{-- Said plainly, because the summary above looks like a bill.
                                     True whether or not online payment is switched on: sending a
                                     request never charges anything, and paying only becomes
                                     possible once the resort has confirmed. --}}
                                <p class="form-text text-center mb-0 mt-2">
                                    Nothing is charged now. {{ $resort->name }} confirms or declines your
                                    request first and works out the final amount.
                                </p>

                                @if (count($preferences) > 1)
                                    <p class="form-text text-center mb-0 mt-1">
                                        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                                        QRPh payments are taken on PayMongo's own secure page. Your
                                        wallet details are never typed into, or stored by, this site.
                                    </p>
                                @endif
                            </aside>
                        </div>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
