@extends('layouts.dashboard')

@section('title', 'Hand in takings')

@section('content')
    <x-page-header title="Hand in takings"
                   subtitle="Turn a period's cash over to the tourism office.">
        <a class="btn btn-light border" href="{{ route('environmental-fee.index') }}">Back to collecting</a>
    </x-page-header>

    {{-- What a row here is, said before anybody fills the form in.
         The module has been careful everywhere else not to let a
         recorded receipt read as money banked, and a handover must not
         start reading that way either. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">This records a handover, not a bank deposit</strong>
            You say what you are turning in; the office counts it and says whether it
            agrees. Visit Dasol cannot see a bank account, so nothing here means the
            money reached the treasury.
        </div>
    </div>

    @if ($checkpoints->isEmpty())
        <x-empty-state icon="ticket-detailed" title="No checkpoint assigned to you"
                       message="The tourism office adds collectors to a checkpoint. Ask them to add you before handing anything in." />
    @else
        <div class="row g-4">
            <div class="col-lg-5">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-1">Hand in a period</h2>
                    <p class="text-muted-vd small mb-3">
                        Only cash receipts are counted here. A GCash payment goes straight
                        to the municipal account, so there is nothing for you to carry.
                    </p>

                    <form method="GET" action="{{ route('environmental-fee.remittances.expected') }}">
                        <x-form.select name="checkpoint_id" label="Checkpoint" required
                                       :options="$checkpoints->pluck('name', 'id')"
                                       :value="old('checkpoint_id')"
                                       placeholder="Choose a checkpoint" />

                        <div class="row">
                            <div class="col-sm-6">
                                <x-form.input name="period_start" type="date" label="First day" required
                                              :value="old('period_start', $defaultStart)"
                                              max="{{ now()->toDateString() }}" />
                            </div>
                            <div class="col-sm-6">
                                <x-form.input name="period_end" type="date" label="Last day" required
                                              :value="old('period_end', $defaultEnd)"
                                              max="{{ now()->toDateString() }}" />
                            </div>
                        </div>

                        {{-- Two steps on purpose. The next screen shows what the
                             receipts say before asking what is in the envelope, so a
                             collector counts against the same figure the office will
                             rather than guessing and being corrected afterwards. --}}
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-calculator me-1" aria-hidden="true"></i>Work out what is owed
                        </button>
                    </form>
                </section>
            </div>

            <div class="col-lg-7">
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">What you have handed in</h2>

                    @forelse ($remittances as $remittance)
                        <a class="vd-remit-row" href="{{ route('environmental-fee.remittances.show', $remittance) }}">
                            <span class="vd-remit-row__main">
                                <span class="font-monospace small d-block">{{ $remittance->reference }}</span>
                                <span class="fw-semibold">{{ $remittance->checkpoint?->name }}</span>
                                <span class="d-block small text-muted-vd">
                                    {{ $remittance->period_start->format('M j') }} to
                                    {{ $remittance->period_end->format('M j, Y') }}
                                    &middot; {{ $remittance->expected_receipts }}
                                    {{ Str::plural('receipt', $remittance->expected_receipts) }}
                                </span>
                            </span>

                            <span class="vd-remit-row__money">
                                <span class="d-block fw-semibold">@peso($remittance->declared_amount, true)</span>
                                <x-status-badge :status="$remittance->status" />
                            </span>
                        </a>
                    @empty
                        <p class="text-muted-vd mb-0">You have not handed anything in yet.</p>
                    @endforelse

                    @if ($remittances->hasPages())
                        <div class="mt-3">{{ $remittances->links() }}</div>
                    @endif
                </section>
            </div>
        </div>
    @endif
@endsection
