@extends('layouts.dashboard')

@section('title', 'Environmental fee')

@section('content')
    <x-page-header title="Environmental fee"
                   subtitle="Record a fee collected at a checkpoint." />

    {{-- The whole answer, up front. While the module is off — which is
         how it ships — there is nothing to fill in, and a collector
         should be told why rather than meeting a form that refuses. --}}
    @if ($blocked)
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">Nothing can be collected right now</strong>
                {{ $blocked }}
            </div>
        </div>
    @elseif ($checkpoints->isEmpty())
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">You are not assigned to a checkpoint</strong>
                The tourism office assigns collectors to each checkpoint separately.
                Ask them to add you to the one you work.
            </div>
        </div>
    @else
        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">Record a collection</h2>
                    <p class="text-muted-vd small mb-3">
                        Write the receipt first, then enter its number here. The amount is
                        worked out from the fee in force &mdash; you do not type it.
                    </p>

                    <form method="POST" action="{{ route('environmental-fee.store') }}"
                          enctype="multipart/form-data" novalidate data-submit-once>
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="field-checkpoint-id">Checkpoint</label>
                            <select class="form-select @error('checkpoint_id') is-invalid @enderror"
                                    id="field-checkpoint-id" name="checkpoint_id" required>
                                @foreach ($checkpoints as $checkpoint)
                                    <option value="{{ $checkpoint->id }}"
                                            @selected(old('checkpoint_id') == $checkpoint->id)>
                                        {{ $checkpoint->name }} — Brgy. {{ $checkpoint->barangay }}
                                    </option>
                                @endforeach
                            </select>
                            @error('checkpoint_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row">
                            <div class="col-sm-7">
                                <x-form.input name="receipt_number" label="Receipt number" required
                                              :value="old('receipt_number')" maxlength="50"
                                              help="As printed on the receipt you issued." />
                            </div>
                            <div class="col-sm-5">
                                <x-form.input name="quantity" type="number"
                                              :label="'Number of '.Str::plural($policy->scope->unit())"
                                              required min="1" max="500"
                                              :value="old('quantity', 1)" />
                            </div>
                        </div>

                        {{-- Cash, or GCash when the office has set it up.
                             Only offered when it can actually be honoured:
                             a choice that fails at the gate is worse than
                             no choice. --}}
                        @if ($gcash)
                            <fieldset class="mb-3">
                                <legend class="form-label">How did they pay?</legend>

                                <label class="vd-paychoice__option">
                                    <input class="form-check-input" type="radio"
                                           name="payment_method" value="cash"
                                           data-fee-method
                                           @checked(old('payment_method', 'cash') === 'cash')>
                                    <span class="vd-paychoice__text">
                                        <span class="vd-paychoice__name">
                                            <i class="bi bi-cash-coin" aria-hidden="true"></i> Cash
                                        </span>
                                        <span class="vd-paychoice__detail">
                                            Handed over at the gate. Counted straight away.
                                        </span>
                                    </span>
                                </label>

                                <label class="vd-paychoice__option">
                                    <input class="form-check-input" type="radio"
                                           name="payment_method" value="gcash"
                                           data-fee-method
                                           @checked(old('payment_method') === 'gcash')>
                                    <span class="vd-paychoice__text">
                                        <span class="vd-paychoice__name">
                                            <i class="bi bi-qr-code" aria-hidden="true"></i> GCash
                                        </span>
                                        <span class="vd-paychoice__detail">
                                            Show the QR. The office confirms the transfer afterwards,
                                            so it does not count yet.
                                        </span>
                                    </span>
                                </label>
                            </fieldset>

                            @error('payment_method')
                                <p class="text-danger small">{{ $message }}</p>
                            @enderror

                            <div data-fee-gcash-fields
                                 @if (old('payment_method') !== 'gcash') hidden @endif>
                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="gcash_reference" label="GCash reference number"
                                                      :value="old('gcash_reference')" maxlength="80"
                                                      help="From the payer's GCash receipt." />
                                    </div>
                                    <div class="col-sm-6">
                                        <x-form.input name="gcash_sender" label="Sent by"
                                                      :value="old('gcash_sender')" maxlength="120"
                                                      help="The name on their GCash account." />
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="field-proof-image">
                                        Screenshot of their receipt
                                        <span class="text-muted-vd fw-normal small">(optional)</span>
                                    </label>
                                    <input type="file"
                                           class="form-control @error('proof_image') is-invalid @enderror"
                                           id="field-proof-image" name="proof_image"
                                           accept="image/png,image/jpeg,image/webp">
                                    @error('proof_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">
                                        Helps the office find the transfer. Kept private to the office.
                                    </div>
                                </div>
                            </div>
                        @else
                            <input type="hidden" name="payment_method" value="cash">
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="field-notes">
                                Notes <span class="text-muted-vd fw-normal small">(optional)</span>
                            </label>
                            <textarea class="form-control @error('notes') is-invalid @enderror"
                                      id="field-notes" name="notes" rows="2"
                                      maxlength="500">{{ old('notes') }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg">Record collection</button>
                    </form>
                </section>
            </div>

            <div class="col-lg-5">
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h6 mb-2">The fee in force</h2>
                    <p class="h3 mb-1">@peso($policy->amount, true)</p>
                    <p class="text-muted-vd small mb-3">{{ $policy->scope->label() }}</p>

                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted-vd fw-normal">Since</dt>
                        <dd class="col-7">{{ $policy->effective_from->format('M j, Y') }}</dd>

                        @if ($policy->authority_reference)
                            <dt class="col-5 text-muted-vd fw-normal">Authority</dt>
                            <dd class="col-7 mb-0">{{ $policy->authority_reference }}</dd>
                        @endif
                    </dl>

                    @if ($policy->notes)
                        <div class="border-top mt-3 pt-3 small text-muted-vd">
                            {{ $policy->notes }}
                        </div>
                    @endif
                </aside>

                {{-- The QR itself, big enough to hold up at a car
                     window. This is the whole point of the screen for a
                     collector working a gate. --}}
                @if ($gcash)
                    <aside class="vd-panel p-4 mb-4 text-center">
                        <h2 class="h6 mb-2">Show this to pay by GCash</h2>

                        <img src="{{ Storage::disk('public')->url($gcash->qr_image) }}"
                             alt="Municipal GCash QR code for the environmental fee"
                             class="img-fluid mb-2" style="max-width: min(260px, 100%)">

                        <p class="mb-0 fw-semibold">{{ $gcash->account_name }}</p>
                        <p class="small text-muted-vd mb-0">{{ $gcash->gcash_number }}</p>

                        @if ($gcash->instructions)
                            <p class="small text-muted-vd mt-2 mb-0">{{ $gcash->instructions }}</p>
                        @endif
                    </aside>
                @endif

                {{-- Said plainly, because a screen that says "Recorded"
                     reads like a payment confirmation and is not one. --}}
                <aside class="vd-panel p-4">
                    <h2 class="h6 mb-2">What this records</h2>
                    <p class="small text-muted-vd mb-2">
                        That you wrote a receipt and took the fee at the gate. It is not an
                        electronic payment, and it does not say the money has reached the
                        municipality &mdash; remittance is handled the way it always was.
                    </p>

                    @if ($gcash)
                        <p class="small text-muted-vd mb-0">
                            A GCash payment you record here waits until the tourism office
                            finds the reference in the GCash app. <strong>Do not treat it as
                            settled at the gate</strong> &mdash; check the payer's own receipt
                            before you let them through.
                        </p>
                    @endif
                </aside>
            </div>
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <h2 class="h5 mb-3">Recently recorded</h2>

        <x-data-table label="Recent environmental fee collections">
            <x-slot:head>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Receipt</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Collected</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Status</th>
                </tr>
            </x-slot:head>

            @foreach ($recent as $collection)
                <tr>
                    <td>
                        <a class="font-monospace small"
                           href="{{ route('environmental-fee.show', $collection) }}">
                            {{ $collection->reference }}
                        </a>
                    </td>
                    <td class="small">{{ $collection->receipt_number }}</td>
                    <td class="small">{{ $collection->checkpoint?->name }}</td>
                    <td class="small">{{ $collection->collected_at->format('M j, g:i A') }}</td>
                    <td class="fw-semibold">@peso($collection->amount, true)</td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $collection->status->badge() }}">
                            {{ $collection->status->label() }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
