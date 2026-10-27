@extends('layouts.admin')

@section('title', 'Environmental fee GCash')

@section('content')
    <x-page-header title="GCash for the environmental fee"
                   subtitle="The municipality's own account, shown at the checkpoints.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.index') }}">The fee</a>
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.verify') }}">Check transfers</a>
    </x-page-header>

    {{-- The single most important sentence on this page. An office that
         believes the portal watches its GCash wallet will stop checking,
         and will report money it was never sent. --}}
    <div class="alert alert-warning d-flex gap-2" role="status">
        <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">This is a QR code, not a payment system</strong>
            Visit Dasol has no connection to your GCash wallet and cannot see a transfer
            arrive. Every GCash payment a collector records waits until somebody from
            your office opens the GCash app, finds the reference, and confirms it. Until
            then it does not count towards any total.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.environmental-fee.gcash.update') }}"
          enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        <div class="row g-4">
            <div class="col-lg-7">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">The account</h2>

                    <x-form.input name="account_name" label="Registered name" required maxlength="120"
                                  :value="old('account_name', $account?->account_name)"
                                  help="Exactly as it appears in GCash, so a payer can check before sending." />

                    <x-form.input name="gcash_number" label="GCash number" required maxlength="30"
                                  :value="old('gcash_number', $account?->gcash_number)" />

                    <div class="mb-3">
                        <label class="form-label" for="field-instructions">
                            Instructions for the payer
                            <span class="text-muted-vd fw-normal small">(optional)</span>
                        </label>
                        <textarea class="form-control @error('instructions') is-invalid @enderror"
                                  id="field-instructions" name="instructions" rows="3"
                                  maxlength="500">{{ old('instructions', $account?->instructions) }}</textarea>
                        @error('instructions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Shown beside the QR. For example: put the plate number in the message.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="field-qr-image">QR code image</label>
                        <input type="file" class="form-control @error('qr_image') is-invalid @enderror"
                               id="field-qr-image" name="qr_image"
                               accept="image/png,image/jpeg,image/webp">
                        @error('qr_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            A screenshot from GCash is fine. PNG, JPG or WEBP, up to 2&nbsp;MB.
                        </div>
                    </div>

                    @if ($qrUrl)
                        <div class="border-top pt-3">
                            <p class="form-label mb-2">The QR code collectors will show</p>
                            <img src="{{ $qrUrl }}" alt="Municipal GCash QR code"
                                 class="img-thumbnail" style="max-width: min(240px, 100%)">

                            <x-form.checkbox name="remove_qr" class="mt-3">
                                Remove this QR code
                            </x-form.checkbox>
                        </div>
                    @endif
                </section>
            </div>

            <div class="col-lg-5">
                <section class="vd-panel p-4 mb-4">
                    <h2 class="h5 mb-3">Taking GCash at the gates</h2>

                    <x-form.checkbox name="active" :checked="(bool) $account?->active">
                        Let collectors take GCash
                    </x-form.checkbox>

                    <p class="form-text mb-0">
                        Off means cash only, and the collection form offers no choice.
                        You cannot switch this on without a QR code &mdash; a collector
                        needs something to hold up at a car window.
                    </p>
                </section>

                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h6 mb-2">How a GCash fee moves</h2>
                    <ol class="small text-muted-vd mb-0 ps-3">
                        <li class="mb-2">The collector shows the QR and writes the paper receipt.</li>
                        <li class="mb-2">They record it here with the payer's GCash reference number.</li>
                        <li class="mb-2">
                            It sits as <strong>waiting to be checked</strong> and counts towards nothing.
                        </li>
                        <li class="mb-0">
                            Your office finds the reference in the GCash app and confirms it. Only then
                            is it in a total.
                        </li>
                    </ol>
                </aside>

                <button type="submit" class="btn btn-primary btn-lg w-100">Save GCash details</button>
            </div>
        </div>
    </form>
@endsection
