@extends('layouts.owner')

@section('title', 'Payments waiting for your check')

@section('content')
<div class="container py-4">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h1 class="h3 mb-1">Payments waiting for your check</h1>
            <p class="text-muted mb-4">{{ $resort->name }}</p>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @forelse ($waiting as $proof)
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <strong>{{ $proof->payment->booking->booking_reference }}</strong>
                                <div class="text-muted small">{{ $proof->payment->payment_reference }}</div>
                            </div>
                            <div class="h4 mb-0">₱{{ number_format((float) $proof->payment->amount, 2) }}</div>
                        </div>

                        <div class="row g-3 small">
                            <div class="col-md-6"><strong>Reference:</strong> {{ $proof->reference_number }}</div>
                            <div class="col-md-6"><strong>Paid from:</strong> {{ $proof->sender_name }}</div>
                            <div class="col-md-6"><strong>Amount they say they sent:</strong> ₱{{ number_format((float) $proof->amount_sent, 2) }}</div>
                            <div class="col-md-6"><strong>Sent:</strong> {{ $proof->created_at->format('M j, Y g:i A') }}</div>
                        </div>

                        <div class="mt-3">
                            {{-- A receipt is worth opening full size, but a tab
                                 appearing out of nowhere needs announcing, and
                                 320px flat overflows the narrowest phones.
                                 min() keeps it 320px where there is room and
                                 lets it shrink where there is not. --}}
                            <x-external-link :href="route('owner.payment-proofs.image', $proof)">
                                <img src="{{ route('owner.payment-proofs.image', $proof) }}"
                                     alt="Receipt screenshot"
                                     class="img-thumbnail"
                                     style="max-width: min(320px, 100%)">
                            </x-external-link>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <form method="POST" action="{{ route('owner.payment-proofs.approve', $proof) }}">
                                @csrf
                                <button type="submit" class="btn btn-success">Approve</button>
                            </form>

                            <form method="POST" action="{{ route('owner.payment-proofs.reject', $proof) }}" class="d-flex gap-2">
                                @csrf
                                <input type="text" name="review_note" required maxlength="250" class="form-control" placeholder="Reason for rejection">
                                <button type="submit" class="btn btn-danger">Reject</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="alert alert-light">Nothing is waiting. 🎉</div>
            @endforelse

            @if ($recent->isNotEmpty())
                <div class="mt-4">
                    <h2 class="h5">Recently reviewed</h2>

                    {{-- Six columns do not fit a phone, so this scrolls
                         sideways. A plain overflow box scrolls with a finger
                         and with nothing else: the columns past the right
                         edge are unreachable by keyboard, and a screen reader
                         is never told the box is there. --}}
                    <x-scroll-region label="Recently reviewed payments">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Booking</th><th>Reference</th><th>Amount</th><th>Result</th><th>By</th><th>When</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($recent as $proof)
                                    <tr>
                                        <td>{{ $proof->payment->booking->booking_reference }}</td>
                                        <td>{{ $proof->reference_number }}</td>
                                        <td>₱{{ number_format((float) $proof->amount_sent, 2) }}</td>
                                        <td>{{ ucfirst($proof->status) }} @if ($proof->review_note) <span class="text-muted">({{ $proof->review_note }})</span> @endif</td>
                                        <td>{{ $proof->reviewer?->name ?? '-' }}</td>
                                        <td>{{ $proof->reviewed_at?->format('M j, g:i A') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-scroll-region>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
