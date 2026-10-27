@extends('layouts.dashboard')

@section('title', 'What is owed')

@section('content')
    <x-page-header title="Hand in takings"
                   :subtitle="$checkpoint->name.' · '.\Illuminate\Support\Carbon::parse($from)->format('M j').' to '.\Illuminate\Support\Carbon::parse($to)->format('M j, Y')">
        <a class="btn btn-light border" href="{{ route('environmental-fee.remittances.index') }}">Start again</a>
    </x-page-header>

    @if ($expected['receipts'] < 1)
        <x-empty-state icon="receipt" title="Nothing to hand in"
                       message="There are no cash receipts from you at this checkpoint for those dates. Check the checkpoint and the dates, or go back and collect first." />
    @else
        <div class="row g-4">
            <div class="col-lg-6">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">What the receipts say</h2>
                    <p class="text-muted-vd small mb-3">
                        Worked out by the server from the receipts you wrote, not typed
                        in by anyone. Voided receipts are already left out.
                    </p>

                    <dl class="vd-remit-figures">
                        <div>
                            <dt>Cash receipts</dt>
                            <dd>{{ $expected['receipts'] }}</dd>
                        </div>
                        <div>
                            <dt>People charged</dt>
                            <dd>{{ $expected['quantity'] }}</dd>
                        </div>
                        <div class="vd-remit-figures__total">
                            <dt>Expected</dt>
                            <dd>@peso($expected['amount'], true)</dd>
                        </div>
                    </dl>

                    <p class="form-text mb-0">
                        GCash payments are not here. They go straight to the municipal
                        account, so there is nothing for you to carry.
                    </p>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">What you are handing over</h2>
                    <p class="text-muted-vd small mb-3">
                        Count the envelope and put the real figure in. If it does not
                        match, say so here rather than making it fit &mdash; a difference
                        with an explanation is something the office can sort out.
                    </p>

                    <form method="POST" action="{{ route('environmental-fee.remittances.store') }}"
                          data-submit-once>
                        @csrf

                        <input type="hidden" name="checkpoint_id" value="{{ $checkpoint->id }}">
                        <input type="hidden" name="period_start" value="{{ $from }}">
                        <input type="hidden" name="period_end" value="{{ $to }}">

                        <div class="mb-3">
                            <label class="form-label" for="field-declared-amount">
                                Amount handed over <span class="text-danger">*</span>
                            </label>
                            <div class="input-group has-validation">
                                <span class="input-group-text">₱</span>
                                <input type="text" inputmode="decimal"
                                       class="form-control @error('declared_amount') is-invalid @enderror"
                                       id="field-declared-amount" name="declared_amount"
                                       value="{{ old('declared_amount', $expected['amount']) }}"
                                       required>
                                @error('declared_amount')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-text">
                                Pre-filled with what the receipts say. Change it if the
                                envelope holds something different.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="field-collector-note">
                                Note <span class="text-muted-vd fw-normal small">(optional)</span>
                            </label>
                            <textarea class="form-control @error('collector_note') is-invalid @enderror"
                                      id="field-collector-note" name="collector_note" rows="3"
                                      placeholder="Anything the office should know — a short count, a torn note, a receipt you could not write.">{{ old('collector_note') }}</textarea>
                            @error('collector_note')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Hand in
                        </button>
                    </form>
                </section>
            </div>
        </div>
    @endif
@endsection
