@extends('layouts.owner')

@section('title', 'GCash for guest payments')

@section('content')
<div class="container py-4">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h1 class="h3 mb-1">GCash for guest payments</h1>
            <p class="text-muted mb-4">{{ $resort->name }}</p>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="alert alert-warning">
                Guests will send money straight to this GCash. After they pay, they send you a reference number and a screenshot.<br>
                <strong>Always check your own GCash app</strong> before approving.
            </div>

            <form method="POST" action="{{ route('owner.manual-gcash.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label for="account_name" class="form-label">Name on the GCash account</label>
                    <input id="account_name" name="account_name" type="text" required maxlength="120" value="{{ old('account_name', $account?->account_name) }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label for="gcash_number" class="form-label">GCash number</label>
                    <input id="gcash_number" name="gcash_number" type="tel" inputmode="numeric" required value="{{ old('gcash_number', $account?->gcash_number) }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label for="instructions" class="form-label">Message for guests</label>
                    <textarea id="instructions" name="instructions" rows="3" maxlength="500" class="form-control">{{ old('instructions', $account?->instructions) }}</textarea>
                </div>

                <div class="mb-3">
                    <label for="qr_image" class="form-label">GCash QR code (optional)</label>
                    @if ($qrUrl)
                        <div class="mb-2">
                            <img src="{{ $qrUrl }}" alt="Your current GCash QR" class="img-thumbnail" style="max-width: 220px">
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="remove_qr" value="1" id="remove_qr">
                            <label class="form-check-label" for="remove_qr">Remove this QR</label>
                        </div>
                    @endif
                    <input id="qr_image" name="qr_image" type="file" accept="image/png,image/jpeg,image/webp" class="form-control">
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="hidden" name="active" value="0">
                    <input class="form-check-input" type="checkbox" name="active" value="1" id="active" @checked(old('active', $account?->active ?? true))>
                    <label class="form-check-label" for="active">Accept GCash payments from guests</label>
                </div>

                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
</div>
@endsection
