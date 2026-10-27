{{--
    What a guest asked for besides the room, and what the resort said.

    Shown to the guest, the owner and the office from one component, so
    the three cannot drift apart about what was asked and what was
    answered.

    owner: true draws the yes/no buttons. The policy still decides —
    this only hides a form the server would refuse anyway.

    NOT ADDED TO THE BOOKING TOTAL ANYWHERE. These are settled at the
    resort, which is said out loud at the bottom rather than left for
    somebody to work out from two figures that do not sum.
--}}
@props(['booking', 'owner' => false])

@php
    $extras = $booking->extras;
@endphp

@if ($extras->isNotEmpty())
    @php
        $centavos = fn ($rows) => $rows->reduce(
            fn ($carry, $row) => $carry + (int) round((float) $row->amount * 100),
            0
        );

        $confirmed = $centavos($extras->where('status', \App\Enums\ExtraStatus::Confirmed));
        $waiting = $centavos($extras->where('status', \App\Enums\ExtraStatus::Requested));
    @endphp

    <section class="vd-panel p-4 mt-4">
        <h2 class="h5 mb-1">Asked for besides the room</h2>
        <p class="text-muted-vd small mb-3">
            @if ($owner)
                The guest asked for these. Answer each one so they know what to expect.
            @else
                The resort answers each of these. They are paid at the resort and are not
                part of the total above.
            @endif
        </p>

        <ul class="vd-extras-list">
            @foreach ($extras as $extra)
                <li class="vd-extras-list__item">
                    <span class="vd-extras-list__what">
                        <span class="d-block fw-semibold">{{ $extra->name }}</span>
                        <span class="small text-muted-vd">
                            {{ $extra->quantity }} × @peso($extra->unit_price),
                            {{ $extra->price_unit->suffix() }}
                        </span>

                        @if (filled($extra->owner_note))
                            <span class="d-block small vd-extras-list__note">
                                <strong>From the resort:</strong> {{ $extra->owner_note }}
                            </span>
                        @endif
                    </span>

                    <span class="vd-extras-list__money">
                        <span class="d-block fw-semibold">@peso($extra->amount, true)</span>
                        <span class="vd-badge vd-badge--{{ $extra->status->badge() }}">
                            {{ $extra->status->label() }}
                        </span>
                    </span>

                    @if ($owner && ! $extra->status->isAnswered())
                        <span class="vd-extras-list__answer">
                            <form method="POST"
                                  action="{{ route('owner.booking-extras.answer', $extra) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="confirmed">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    Yes, we can
                                </button>
                            </form>

                            {{-- A reason is required to refuse, so declining is
                                 a small form rather than a one-tap button: "no"
                                 on its own is the answer that generates a phone
                                 call. --}}
                            <details class="vd-extras-list__decline">
                                <summary class="btn btn-sm btn-light border">Cannot do it</summary>

                                <form method="POST"
                                      action="{{ route('owner.booking-extras.answer', $extra) }}"
                                      class="vd-extras-list__declineform">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="declined">

                                    <label class="form-label small"
                                           for="field-note-{{ $extra->id }}">Why not?</label>
                                    <textarea class="form-control form-control-sm"
                                              id="field-note-{{ $extra->id }}"
                                              name="owner_note" rows="2" required
                                              placeholder="No boat that day, weather, already booked…"></textarea>

                                    <button type="submit" class="btn btn-sm btn-danger mt-2 w-100">
                                        Tell the guest
                                    </button>
                                </form>
                            </details>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>

        <dl class="row vd-extras-list__totals mb-0">
            @if ($confirmed > 0)
                <dt class="col-8 fw-semibold">Confirmed, payable at the resort</dt>
                <dd class="col-4 text-end fw-semibold mb-0">
                    @peso(number_format($confirmed / 100, 2, '.', ''), true)
                </dd>
            @endif

            @if ($waiting > 0)
                <dt class="col-8 text-muted-vd fw-normal">Still waiting for an answer</dt>
                <dd class="col-4 text-end text-muted-vd mb-0">
                    @peso(number_format($waiting / 100, 2, '.', ''), true)
                </dd>
            @endif
        </dl>

        <p class="form-text mb-0 mt-2">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            These are separate from the reservation total and are settled with the
            resort. Nothing here changes what is due before arrival.
        </p>
    </section>
@endif
