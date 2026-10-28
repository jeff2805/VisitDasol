@extends('layouts.public')

@section('title', 'Pay by GCash')

@section('content')
<div class="container py-5">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h1 class="h3 mb-3">Pay by GCash</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mb-4">
                <p class="text-muted mb-1">Reservation {{ $booking->booking_reference }}</p>
                <h2 class="display-6 mb-0">₱{{ number_format((float) $payment->amount, 2) }}</h2>
            </div>

            @if ($payment->isSettled())
                <div class="alert alert-success">Payment confirmed by the resort. Thank you!</div>
            @elseif ($account === null)
                {{-- The resort has no active GCash account on file. The
                     controller passes null for it, and the page used to
                     read account_name off that null: three warnings in
                     the log and, worse, a form asking the guest to
                     upload proof of a payment they had no number to
                     send. Say so instead, and offer the resort's phone
                     number, which is the only thing that can actually
                     move this along. --}}
                <div class="alert alert-warning">
                    <strong class="d-block">This resort has not published its GCash details.</strong>
                    Nothing has been charged and your reservation is unaffected. Please contact
                    {{ $booking->resort?->name }}
                    @if (filled($booking->resort?->contact_number))
                        on {{ $booking->resort->contact_number }}
                    @endif
                    to arrange payment.
                </div>
            @else
                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h5">1. Send the exact amount to this GCash</h3>
                        <p class="mb-1"><strong>Account name:</strong> {{ $account->account_name }}</p>
                        <p class="mb-1"><strong>GCash number:</strong> {{ $account->gcash_number }}</p>
                        @if ($qrUrl)
                            <div class="mt-3">
                                <img src="{{ $qrUrl }}" alt="GCash QR code" class="img-thumbnail" style="max-width: 220px">
                            </div>
                        @endif
                        @if ($account->instructions)
                            <div class="alert alert-light mt-3 mb-0">{{ $account->instructions }}</div>
                        @endif
                    </div>
                </div>

                @if ($proof && $proof->status === \App\Models\PaymentProof::SUBMITTED)
                    <div class="alert alert-info">Waiting for the resort. You sent reference {{ $proof->reference_number }}.</div>
                @else
                    @if ($proof && $proof->status === \App\Models\PaymentProof::REJECTED)
                        <div class="alert alert-danger">The resort could not find this payment. Reason: {{ $proof->review_note }}</div>
                    @endif

                    {{-- The route is named tourist.manual-payments.submit and
                         takes the booking alone; the controller finds the
                         latest manual_gcash payment itself. "manual-payments.submit"
                         with a payment parameter matched nothing, so route()
                         threw and the whole page 500'd: no guest could ever
                         submit GCash proof. --}}
                    <form method="POST" action="{{ route('tourist.manual-payments.submit', $booking) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="reference_number" class="form-label">GCash reference number</label>
                            <input id="reference_number" name="reference_number" type="text" required class="form-control" value="{{ old('reference_number') }}">
                        </div>

                        <div class="mb-3">
                            <label for="sender_name" class="form-label">Name of the GCash account you paid from</label>
                            <input id="sender_name" name="sender_name" type="text" required class="form-control" value="{{ old('sender_name') }}">
                        </div>

                        <div class="mb-3">
                            <label for="amount_sent" class="form-label">Amount you sent (₱)</label>
                            <input id="amount_sent" name="amount_sent" type="number" step="0.01" required class="form-control" value="{{ old('amount_sent', number_format((float) $payment->amount, 2, '.', '')) }}">
                        </div>

                        <div class="mb-3">
                            <label for="proof_image" class="form-label">Screenshot of your GCash receipt</label>
                            <input id="proof_image" name="proof_image" type="file" required accept="image/png,image/jpeg,image/webp" class="form-control">
                        </div>

                        <button type="submit" class="btn btn-primary">Send proof</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
