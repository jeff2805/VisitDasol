@extends('layouts.owner')

@section('title', $booking->booking_reference)

@section('content')
    <x-page-header :title="$booking->booking_reference" :subtitle="$booking->room?->name">
        <a class="btn btn-light border" href="{{ route('owner.bookings.index') }}">Back to reservations</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <h2 class="h5 mb-0">The stay</h2>
                    <x-status-badge :status="$booking->status" />
                </div>

                <dl class="row mb-0">
                    <x-stay-dates :booking="$booking" />

                    <dt class="col-sm-4 text-muted-vd fw-normal">Rooms</dt>
                    <dd class="col-sm-8">{{ $booking->rooms_booked }} x {{ $booking->room?->name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Guests</dt>
                    <dd class="col-sm-8"><x-guest-party :booking="$booking" /></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Requested</dt>
                    <dd class="col-sm-8 mb-0">{{ $booking->created_at->format('M j, Y g:i A') }}</dd>
                </dl>

                @if (filled($booking->special_requests))
                    <div class="alert alert-info mt-3 mb-0" role="status">
                        <strong class="d-block">From the guest</strong>
                        {{ $booking->special_requests }}
                    </div>
                @endif
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Amount</h2>

                {{-- The discounts the guest was quoted, so the figure at the
                     gate is the figure they were shown, and your staff know
                     which IDs to ask for before taking the money. --}}
                <x-price-breakdown :booking="$booking" />

                {{-- The terms this guest agreed to, which are the terms that
                     stand even if you have changed your policy since. What
                     to collect on arrival is the balance, not the total. --}}
                <div class="border-top mt-3 pt-3">
                    <x-payment-terms :terms="$terms" :paid="$paid"
                                     heading="Agreed payment terms" />

                    @if ($dueNow > 0 && $booking->status === \App\Enums\BookingStatus::Confirmed)
                        <p class="form-text mb-0 mt-2">
                            <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
                            <strong>@peso($dueNow, true)</strong> of this is still unpaid.
                            Confirming a booking does not collect it.
                        </p>
                    @endif
                </div>

                {{-- What the guest said they would do when they sent the request.
                     The resort has to know whether to expect money at the gate,
                     and this is the only place on their side that answers it. --}}
                @if ($booking->payment_preference)
                    <dl class="row mb-0 mt-3 pt-3 border-top">
                        <dt class="col-sm-8 text-muted-vd fw-normal">Guest chose to pay by</dt>
                        <dd class="col-sm-4 text-sm-end fw-semibold mb-0">
                            <i class="bi bi-{{ $booking->payment_preference->icon() }}" aria-hidden="true"></i>
                            {{ $booking->payment_preference->label() }}
                        </dd>
                    </dl>
                @endif

                <p class="form-text mb-0">
                    @if ($booking->payment_preference === \App\Enums\PaymentPreference::Online)
                        This guest intends to pay by QRPh once you confirm. A payment that clears
                        is recorded on this reservation; collect anything still outstanding at your
                        resort as usual.
                    @else
                        Visit Dasol does not take payment. Collect it at your resort as usual.
                    @endif
                </p>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Guest</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Name</dt>
                    <dd class="col-7">{{ $booking->contact_name }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-7"><a href="tel:{{ $booking->contact_phone }}">{{ $booking->contact_phone }}</a></dd>

                    {{-- The address given on this request, which may not be the
                         one the account was opened with. Reply here first. --}}
                    @if ($booking->contact_email)
                        <dt class="col-5 text-muted-vd fw-normal">Email for this booking</dt>
                        <dd class="col-7 text-break"><a href="mailto:{{ $booking->contact_email }}">{{ $booking->contact_email }}</a></dd>
                    @endif

                    <dt class="col-5 text-muted-vd fw-normal">Account email</dt>
                    <dd class="col-7 text-break"><a href="mailto:{{ $booking->tourist?->email }}">{{ $booking->tourist?->email }}</a></dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Your decision</h2>

                @if ($booking->status === \App\Enums\BookingStatus::Pending)
                    <p class="text-muted-vd">
                        The dates are held while this request is waiting. Answering quickly frees them for
                        other guests if you cannot take the booking.
                    </p>

                    <div class="d-flex flex-wrap gap-2">
                        @can('confirm', $booking)
                            <x-confirm-form id="confirm-booking"
                                            :action="route('owner.bookings.confirm', $booking)"
                                            title="Confirm this reservation?"
                                            message="The guest sees it as confirmed and will arrive on the check-in date."
                                            trigger="Confirm"
                                            confirm="Yes, confirm"
                                            icon="check-lg"
                                            variant="primary" />
                        @else
                            <span class="btn btn-primary disabled" aria-disabled="true"
                                  title="The arrival date has passed">Confirm</span>
                        @endcan

                        <x-confirm-form id="reject-booking"
                                        :action="route('owner.bookings.reject', $booking)"
                                        title="Decline this request?"
                                        message="The dates are freed for other guests, and the guest sees your reason."
                                        trigger="Decline"
                                        confirm="Decline request"
                                        icon="x-lg"
                                        variant="danger"
                                        trigger-class="btn btn-outline-danger">
                            <div class="mt-3">
                                <label class="form-label" for="owner_remarks">Reason for the guest</label>
                                <textarea class="form-control @error('owner_remarks') is-invalid @enderror"
                                          id="owner_remarks" name="owner_remarks" rows="3" required
                                          placeholder="For example: those rooms are already taken by a walk-in group.">{{ old('owner_remarks') }}</textarea>
                                @error('owner_remarks')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </x-confirm-form>
                    </div>
                @elseif ($booking->status === \App\Enums\BookingStatus::Confirmed)
                    <div class="d-flex flex-wrap gap-2">
                        @can('complete', $booking)
                            <x-confirm-form id="complete-booking"
                                            :action="route('owner.bookings.complete', $booking)"
                                            title="Mark this stay completed?"
                                            message="Use this once the guest has checked out. It keeps your records straight."
                                            trigger="Mark completed"
                                            confirm="Yes, completed"
                                            icon="check2-all"
                                            variant="primary" />
                        @else
                            <span class="btn btn-primary disabled" aria-disabled="true"
                                  title="Available after the check-out date">Mark completed</span>
                        @endcan

                        @can('cancelAsOwner', $booking)
                            <x-confirm-form id="cancel-booking"
                                            :action="route('owner.bookings.cancel', $booking)"
                                            title="Cancel this confirmed stay?"
                                            message="Only for real problems such as storm damage or repairs. The guest sees your reason."
                                            trigger="Cancel stay"
                                            confirm="Yes, cancel it"
                                            icon="x-octagon"
                                            variant="danger"
                                            trigger-class="btn btn-outline-danger">
                                <div class="mt-3">
                                    <label class="form-label" for="cancellation_reason">Reason for the guest</label>
                                    <textarea class="form-control @error('cancellation_reason') is-invalid @enderror"
                                              id="cancellation_reason" name="cancellation_reason" rows="3" required>{{ old('cancellation_reason') }}</textarea>
                                    @error('cancellation_reason')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </x-confirm-form>
                        @endcan
                    </div>
                @else
                    <p class="mb-2">This reservation is <x-status-badge :status="$booking->status" />.</p>

                    @if (filled($booking->owner_remarks))
                        <p class="small text-muted-vd mb-1"><strong>Your note:</strong> {{ $booking->owner_remarks }}</p>
                    @endif
                    @if (filled($booking->cancellation_reason))
                        <p class="small text-muted-vd mb-0">
                            <strong>Cancelled{{ $booking->cancelledBy ? ' by '.$booking->cancelledBy->full_name : '' }}:</strong>
                            {{ $booking->cancellation_reason }}
                        </p>
                    @endif
                @endif
            </section>
        </div>
    </div>
@endsection
