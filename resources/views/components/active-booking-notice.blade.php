{{--
    "You already have a booking somewhere else."

    Shown wherever a guest is about to start a second reservation they
    cannot finish. BookingService refuses it anyway; this is so they are
    told BEFORE filling in a form, not after.

    Naming the resort, the dates and the status matters. "You have an
    active booking" leaves a guest hunting through their account for
    something they may have forgotten; this tells them what it is and
    gives them the two things they can actually do about it.

    compact: a one-line banner for a listing page, rather than the full
    panel the booking form shows.
--}}
@props(['booking', 'compact' => false])

@php
    $isPending = $booking->status === \App\Enums\BookingStatus::Pending;
@endphp

@if ($compact)
    <div {{ $attributes->class('alert alert-warning d-flex flex-wrap align-items-center gap-2') }} role="status">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span class="flex-grow-1">
            You already have {{ $isPending ? 'a request waiting at' : 'a booking at' }}
            <strong>{{ $booking->resort?->name }}</strong>.
            You can only hold one at a time.
        </span>
        <a class="btn btn-sm btn-outline-primary"
           href="{{ route('tourist.bookings.show', $booking) }}">See it</a>
    </div>
@else
    <section {{ $attributes->class('vd-panel p-4') }} aria-labelledby="active-booking-title">
        <div class="d-flex gap-3">
            <i class="bi bi-exclamation-triangle-fill fs-3 text-warning flex-shrink-0" aria-hidden="true"></i>

            <div class="min-w-0">
                <h2 class="h5 mb-2" id="active-booking-title">
                    You already have a booking
                </h2>

                <p class="text-muted-vd">
                    Visit Dasol holds one reservation per guest at a time, so the rooms you
                    are not using go back to other visitors. Finish or call off the one below
                    and you can book again straight away.
                </p>

                <dl class="row small mb-3">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Reference</dt>
                    <dd class="col-sm-8 font-monospace">{{ $booking->booking_reference }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Resort</dt>
                    <dd class="col-sm-8">
                        {{ $booking->resort?->name }}
                        @if ($booking->resort?->barangay)
                            <span class="text-muted-vd">· Brgy. {{ $booking->resort->barangay }}</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Room</dt>
                    <dd class="col-sm-8">
                        {{ $booking->rooms_booked }} x {{ $booking->room?->name }}
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Dates</dt>
                    <dd class="col-sm-8">
                        {{ $booking->check_in_date->format('M j') }} to
                        {{ $booking->check_out_date->format('M j, Y') }}
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Status</dt>
                    <dd class="col-sm-8 mb-0"><x-status-badge :status="$booking->status" /></dd>
                </dl>

                {{-- The two things they can actually do. Cancelling is
                     offered from here because it is the step that unblocks
                     them, and making them hunt for it is the whole
                     frustration this notice exists to end. --}}
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary"
                       href="{{ route('tourist.bookings.show', $booking) }}">
                        <i class="bi bi-eye me-1" aria-hidden="true"></i>Go to this booking
                    </a>

                    @can('cancelAsTourist', $booking)
                        <x-confirm-form id="cancel-active-booking"
                                        :action="route('tourist.bookings.cancel', $booking)"
                                        title="Cancel this reservation?"
                                        :message="'This frees the rooms at '.$booking->resort?->name.' for other guests and cannot be undone. You can book somewhere else afterwards.'"
                                        trigger="Cancel it and book elsewhere"
                                        confirm="Yes, cancel it"
                                        icon="x-circle"
                                        variant="danger"
                                        trigger-class="btn btn-outline-danger">
                            <div class="mt-3">
                                <label class="form-label" for="cancellation_reason">
                                    Reason <span class="text-muted-vd fw-normal small">(optional, the resort sees this)</span>
                                </label>
                                <textarea class="form-control" id="cancellation_reason"
                                          name="cancellation_reason" rows="2" maxlength="500"></textarea>
                            </div>
                        </x-confirm-form>
                    @else
                        {{-- Too close to arrival to call off here. Saying so
                             beats leaving them looking for a button that is
                             deliberately not there. --}}
                        <span class="align-self-center small text-muted-vd">
                            It is too close to your arrival to cancel online. Call
                            {{ $booking->resort?->name }} to change it.
                        </span>
                    @endcan
                </div>
            </div>
        </div>
    </section>
@endif
