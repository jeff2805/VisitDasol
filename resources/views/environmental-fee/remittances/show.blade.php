@extends('layouts.dashboard')

@section('title', $remittance->reference)

@section('content')
    <x-page-header :title="$remittance->reference"
                   :subtitle="$remittance->checkpoint?->name">
        <a class="btn btn-light border" href="{{ route('environmental-fee.remittances.index') }}">Back</a>

        {{-- Hidden until app.js reveals it: a print button that does
             nothing without JavaScript is worse than none, and Ctrl+P
             still prints the same slip either way. --}}
        <button type="button" class="btn btn-primary" data-print hidden>
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Print slip
        </button>
    </x-page-header>

    <x-remittance-slip :remittance="$remittance" :receipts="$receipts" :live="$live" />
@endsection
