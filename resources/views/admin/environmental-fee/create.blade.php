@extends('layouts.admin')

@section('title', 'Record a fee configuration')

@section('content')
    <x-page-header title="Record a fee configuration"
                   subtitle="What the ordinance says. Saving this does not start collection.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.index') }}">Cancel</a>
    </x-page-header>

    {{-- Said before the form, not after it. Writing down a rate and
         beginning to charge visitors are two decisions, and somebody
         filling this in should know that the second is still ahead of
         them. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">This is a record, not a switch</strong>
            The configuration is saved switched off. Nothing is collected from anyone until
            you turn it on from the list, and that decision is recorded with your name
            against it.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.environmental-fee.store') }}" novalidate data-submit-once>
        @csrf

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">The charge</h2>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="mb-3">
                                <label class="form-label" for="field-amount">Fee amount</label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" step="0.01" min="0.01" max="100000"
                                           class="form-control @error('amount') is-invalid @enderror"
                                           id="field-amount" name="amount"
                                           value="{{ old('amount') }}" required>
                                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="form-text">The figure set by the ordinance.</div>
                            </div>
                        </div>

                        <div class="col-sm-6">
                            <div class="mb-3">
                                <label class="form-label" for="field-scope">Charged for each</label>
                                <select class="form-select @error('scope') is-invalid @enderror"
                                        id="field-scope" name="scope" required>
                                    <option value="" disabled @selected(! old('scope'))>Choose one</option>
                                    @foreach (\App\Enums\FeeScope::cases() as $case)
                                        <option value="{{ $case->value }}" @selected(old('scope') === $case->value)>
                                            {{ $case->label() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">When it applies</h2>

                    <div class="row">
                        <div class="col-sm-6">
                            <x-form.input name="effective_from" type="date" label="Takes effect" required
                                          :value="old('effective_from')" />
                        </div>
                        <div class="col-sm-6">
                            <x-form.input name="effective_until" type="date" label="Stops applying"
                                          :value="old('effective_until')"
                                          help="Leave blank if it runs until repealed." />
                        </div>
                    </div>

                    <x-form.input name="authority_reference" label="Ordinance or resolution"
                                  :value="old('authority_reference')" maxlength="150"
                                  help="For example: Municipal Ordinance No. 2026-04. Recorded as you type it." />

                    <div class="mb-0">
                        <label class="form-label" for="field-notes">
                            Notes <span class="text-muted-vd fw-normal small">(optional)</span>
                        </label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="field-notes" name="notes" rows="4"
                                  placeholder="Exemptions, categories, or anything collectors need to know.">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Free text. The portal does not interpret exemptions or visitor
                            categories &mdash; those stay with the ordinance and with your collectors.
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h6 mb-2">What this module will not do</h2>
                    <ul class="small text-muted-vd mb-0 ps-3">
                        <li class="mb-2">
                            It never adds the fee to a resort booking, a room rate or a deposit.
                            Guests see no change to what they owe a resort.
                        </li>
                        <li class="mb-2">
                            It records receipts written at a checkpoint. It does not take payments
                            online and cannot say that money was remitted or banked.
                        </li>
                        <li class="mb-0">
                            It does not decide who is exempt. Collectors apply the ordinance.
                        </li>
                    </ul>
                </aside>

                <button type="submit" class="btn btn-primary btn-lg w-100">Save configuration</button>
            </div>
        </div>
    </form>
@endsection
