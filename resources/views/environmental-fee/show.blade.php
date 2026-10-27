@extends('layouts.dashboard')

@section('title', 'Collection '.$collection->reference)

@section('content')
    <x-page-header :title="$collection->reference"
                   subtitle="One environmental fee recorded at a checkpoint.">
        <a class="btn btn-light border" href="{{ route('environmental-fee.index') }}">Back</a>
    </x-page-header>

    @if ($collection->status === \App\Enums\CollectionStatus::Voided)
        <div class="alert alert-danger d-flex gap-2" role="status">
            <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">
                    Voided@if ($collection->voided_at) on {{ $collection->voided_at->format('M j, Y g:i A') }}@endif
                    @if ($collection->voidedBy) by {{ $collection->voidedBy->full_name }}@endif
                </strong>
                {{ $collection->void_reason }}
                <span class="d-block mt-1">This no longer counts towards any total.</span>
            </div>
        </div>
    @elseif ($collection->status === \App\Enums\CollectionStatus::Corrected)
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-arrow-repeat mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">Replaced by a correction</strong>
                A later record supersedes this one, so it no longer counts towards any total.
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">What was collected</h2>

                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Receipt number</dt>
                    <dd class="col-sm-8 font-monospace">{{ $collection->receipt_number }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Checkpoint</dt>
                    <dd class="col-sm-8">
                        {{ $collection->checkpoint?->name }}
                        <span class="text-muted-vd">· Brgy. {{ $collection->checkpoint?->barangay }}</span>
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Collector</dt>
                    <dd class="col-sm-8">{{ $collection->collector?->full_name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Collected</dt>
                    <dd class="col-sm-8">{{ $collection->collected_at->format('l, M j, Y g:i A') }}</dd>

                    {{-- The rate as it stood that day, not as it stands
                         now. An office that changes the fee next month
                         must not change what this receipt says. --}}
                    <dt class="col-sm-4 text-muted-vd fw-normal">Rate applied</dt>
                    <dd class="col-sm-8">
                        @peso($collection->rate, true)
                        <span class="text-muted-vd">{{ $collection->scope->label() }}</span>
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Quantity</dt>
                    <dd class="col-sm-8">
                        {{ $collection->quantity }}
                        {{ Str::plural($collection->scope->unit(), $collection->quantity) }}
                    </dd>

                    <dt class="col-sm-4 fw-semibold border-top pt-2">Total</dt>
                    <dd class="col-sm-8 fw-semibold border-top pt-2">
                        @peso($collection->amount, true)
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Paid by</dt>
                    <dd class="col-sm-8">
                        @if ($collection->isGcash())
                            GCash
                            <span class="vd-badge vd-badge--{{ $collection->payment_status->badge() }} ms-1">
                                {{ $collection->payment_status->label() }}
                            </span>
                        @else
                            Cash at the checkpoint
                        @endif
                    </dd>

                    @if ($collection->isGcash())
                        <dt class="col-sm-4 text-muted-vd fw-normal">GCash reference</dt>
                        <dd class="col-sm-8 font-monospace">{{ $collection->gcash_reference }}</dd>

                        @if ($collection->gcash_sender)
                            <dt class="col-sm-4 text-muted-vd fw-normal">Sent by</dt>
                            <dd class="col-sm-8">{{ $collection->gcash_sender }}</dd>
                        @endif

                        @if ($collection->verified_at)
                            <dt class="col-sm-4 text-muted-vd fw-normal">Checked</dt>
                            <dd class="col-sm-8">
                                {{ $collection->verified_at->format('M j, Y g:i A') }}
                                @if ($collection->verifiedBy)
                                    <span class="text-muted-vd">by {{ $collection->verifiedBy->full_name }}</span>
                                @endif
                                @if ($collection->verification_note)
                                    <span class="d-block small text-muted-vd">
                                        {{ $collection->verification_note }}
                                    </span>
                                @endif
                            </dd>
                        @endif
                    @endif

                    @if ($collection->notes)
                        <dt class="col-sm-4 text-muted-vd fw-normal">Notes</dt>
                        <dd class="col-sm-8 mb-0">{{ $collection->notes }}</dd>
                    @endif
                </dl>
            </section>
        </div>

        <div class="col-lg-5">
            <aside class="vd-panel p-4 mb-4">
                <h2 class="h6 mb-2">What this is</h2>
                <p class="small text-muted-vd mb-0">
                    A record that a collector wrote a receipt and took this fee at a gate.
                    It is municipal money, entirely separate from any resort booking, and
                    it is not a record that the amount has been remitted or banked.
                </p>
            </aside>

            @if ($collection->awaitsVerification())
                <aside class="vd-panel p-4 mb-4 border-warning">
                    <h2 class="h6 mb-2">
                        <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
                        This transfer has not been checked
                    </h2>
                    <p class="small text-muted-vd mb-0">
                        Visit Dasol cannot see the municipal GCash wallet. Until somebody
                        from the tourism office finds this reference in the app, the money
                        is not counted in any total.
                    </p>
                </aside>
            @endif

            @can('void', $collection)
                <aside class="vd-panel p-4">
                    <h2 class="h6 mb-2">Something wrong with it?</h2>
                    <p class="small text-muted-vd mb-3">
                        A collection is never deleted. Voiding leaves the record in place
                        with your reason against it, so the trail stays whole.
                    </p>

                    <x-confirm-form id="void-collection"
                                    :action="route('environmental-fee.void', $collection)"
                                    title="Void this collection?"
                                    message="It stays on the record but stops counting towards any total. This cannot be undone."
                                    trigger="Void this collection"
                                    confirm="Yes, void it"
                                    icon="x-circle"
                                    variant="danger"
                                    trigger-class="btn btn-outline-danger">
                        <div class="mt-3">
                            <label class="form-label" for="void_reason">Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="void_reason" name="void_reason"
                                      rows="3" maxlength="500" required></textarea>
                            <div class="form-text">
                                Written into the audit trail. Say enough that somebody reading
                                this next year understands what happened.
                            </div>
                        </div>
                    </x-confirm-form>
                </aside>
            @endcan
        </div>
    </div>
@endsection
