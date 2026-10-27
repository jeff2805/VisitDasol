```blade
@extends('layouts.owner')

@section('title', 'GCash Payment Settings')

@section('content')
<div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">GCash Payment Settings</h1>
            <p class="text-muted mb-0">
                Manage GCash payments for {{ $resort->name }}.
            </p>
        </div>

        <a href="{{ route('owner.resort.show') }}"
           class="btn btn-light border">
            <i class="bi bi-arrow-left me-1"></i>
            Back to My Resort
        </a>
    </div>

    {{-- Success message --}}
    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="status">
            <i class="bi bi-check-circle me-2"></i>
            {{ session('status') }}
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Validation errors --}}
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Please check the following:</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-shield-exclamation fs-5"></i>
        <div>
            <strong>Important payment reminder</strong>
            <p class="mb-0">
                Guests will send payment directly to the GCash account you provide.
                Verify the actual transaction in your GCash app before approving
                any payment proof. Never rely on a screenshot alone.
            </p>
        </div>
    </div>

    <form method="POST"
          action="{{ route('owner.manual-gcash.update') }}"
          enctype="multipart/form-data">

        @csrf
        @method('PUT')

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h5 mb-0">
                    <i class="bi bi-wallet2 me-2"></i>
                    GCash Account Information
                </h2>
            </div>

            <div class="card-body p-4">

                <div class="mb-3">
                    <label for="account_name" class="form-label">
                        Account Name <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        id="account_name"
                        name="account_name"
                        maxlength="120"
                        required
                        autocomplete="name"
                        value="{{ old('account_name', $account?->account_name) }}"
                        class="form-control @error('account_name') is-invalid @enderror"
                        placeholder="Enter the registered GCash account name"
                    >

                    @error('account_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="gcash_number" class="form-label">
                        GCash Mobile Number <span class="text-danger">*</span>
                    </label>

                    <input
                        type="tel"
                        id="gcash_number"
                        name="gcash_number"
                        maxlength="30"
                        required
                        inputmode="tel"
                        value="{{ old('gcash_number', $account?->gcash_number) }}"
                        class="form-control @error('gcash_number') is-invalid @enderror"
                        placeholder="e.g. 09XXXXXXXXX"
                    >

                    @error('gcash_number')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    <div class="form-text">
                        Enter the GCash number where your guests should send payment.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="instructions" class="form-label">
                        Payment Instructions for Guests
                    </label>

                    <textarea
                        id="instructions"
                        name="instructions"
                        rows="4"
                        maxlength="500"
                        class="form-control @error('instructions') is-invalid @enderror"
                        placeholder="Explain how guests should pay and submit their payment reference."
                    >{{ old('instructions', $account?->instructions) }}</textarea>

                    @error('instructions')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h5 mb-0">
                    <i class="bi bi-qr-code me-2"></i>
                    GCash QR Code
                </h2>
            </div>

            <div class="card-body p-4">

                @if ($qrUrl)
                    <div class="mb-3">
                        <p class="form-label">Current QR Code</p>

                        <div class="p-3 border rounded text-center"
                             style="max-width: 320px;">
                            <img
                                src="{{ $qrUrl }}"
                                alt="Current GCash QR code"
                                class="img-fluid"
                                style="max-height: 280px; object-fit: contain;"
                            >
                        </div>

                        <div class="form-check mt-3">
                            <input
                                type="checkbox"
                                id="remove_qr"
                                name="remove_qr"
                                value="1"
                                class="form-check-input"
                                @checked(old('remove_qr'))
                            >

                            <label for="remove_qr" class="form-check-label">
                                Remove current QR code
                            </label>
                        </div>
                    </div>
                @else
                    <div class="text-center border rounded p-4 mb-3"
                         style="max-width: 320px;">
                        <i class="bi bi-qr-code display-3 text-secondary"></i>
                        <p class="text-muted mb-0 mt-2">
                            No QR code uploaded yet.
                        </p>
                    </div>
                @endif

                <div class="mb-3">
                    <label for="qr_image" class="form-label">
                        Upload QR Image
                    </label>

                    <input
                        type="file"
                        id="qr_image"
                        name="qr_image"
                        accept="image/png,image/jpeg,image/webp"
                        class="form-control @error('qr_image') is-invalid @enderror"
                    >

                    @error('qr_image')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    <div class="form-text">
                        Accepted formats: PNG, JPG, JPEG, and WebP.
                        Maximum file size: 2 MB.
                    </div>
                </div>

            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">
                    <i class="bi bi-toggle-on me-2"></i>
                    Payment Availability
                </h2>

                <input type="hidden" name="active" value="0">

                <div class="form-check form-switch">
                    <input
                        type="checkbox"
                        id="active"
                        name="active"
                        value="1"
                        class="form-check-input"
                        role="switch"
                        @checked(old('active', $account?->active ?? false))
                    >

                    <label for="active" class="form-check-label fw-semibold">
                        Accept manual GCash payments
                    </label>
                </div>

                <p class="text-muted small mt-2 mb-0">
                    When enabled, your GCash account can be offered to guests
                    through the manual payment workflow, provided the booking
                    checkout is configured to use it.
                </p>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check-lg me-1"></i>
                Save GCash Settings
            </button>

            <a href="{{ route('owner.resort.show') }}"
               class="btn btn-light border btn-lg">
                Cancel
            </a>
        </div>

    </form>
</div>
@endsection
```