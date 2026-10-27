@extends('layouts.admin')

@section('title', 'Check GCash transfers')

@section('content')
    <x-page-header title="Check GCash transfers"
                   subtitle="Confirm what actually arrived in the municipal account.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.gcash.edit') }}">GCash details</a>
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.reports') }}">Collections</a>
    </x-page-header>

    {{-- Said here as well as on the settings page, because this is the
         screen somebody works through every morning. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">Open the GCash app beside this page</strong>
            Visit Dasol cannot see your wallet. Find each reference below in GCash, check
            the amount matches, then say what you found. Nothing here counts towards a
            total until you do.
        </div>
    </div>

    @if ($waiting->isEmpty())
        <x-empty-state icon="check2-circle" title="Nothing waiting"
                       message="Every GCash transfer recorded at the gates has been checked." />
    @else
        <h2 class="h5 mb-3">
            Waiting to be checked ({{ $waiting->count() }})
        </h2>

        <div class="row g-3 mb-4">
            @foreach ($waiting as $collection)
                <div class="col-lg-6">
                    <article class="vd-panel p-4 h-100">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <span>
                                <span class="d-block fw-semibold font-monospace">
                                    {{ $collection->gcash_reference }}
                                </span>
                                <span class="small text-muted-vd">
                                    GCash reference, as the payer gave it
                                </span>
                            </span>
                            <span class="h4 mb-0">@peso($collection->amount, true)</span>
                        </div>

                        <dl class="row small mb-3">
                            <dt class="col-5 text-muted-vd fw-normal">Our reference</dt>
                            <dd class="col-7 font-monospace">
                                <a href="{{ route('environmental-fee.show', $collection) }}">
                                    {{ $collection->reference }}
                                </a>
                            </dd>

                            <dt class="col-5 text-muted-vd fw-normal">Paper receipt</dt>
                            <dd class="col-7">{{ $collection->receipt_number }}</dd>

                            <dt class="col-5 text-muted-vd fw-normal">Checkpoint</dt>
                            <dd class="col-7">{{ $collection->checkpoint?->name }}</dd>

                            <dt class="col-5 text-muted-vd fw-normal">Collector</dt>
                            <dd class="col-7">{{ $collection->collector?->full_name }}</dd>

                            <dt class="col-5 text-muted-vd fw-normal">Recorded</dt>
                            <dd class="col-7">{{ $collection->collected_at->format('M j, Y g:i A') }}</dd>

                            @if ($collection->gcash_sender)
                                <dt class="col-5 text-muted-vd fw-normal">Sent by</dt>
                                <dd class="col-7 mb-0">{{ $collection->gcash_sender }}</dd>
                            @endif
                        </dl>

                        @if ($collection->proof_image)
                            {{-- Behind a route, not a public URL: the screenshot
                                 carries a stranger's GCash name and balance. --}}
                            <p class="mb-3">
                                <x-external-link class="btn btn-sm btn-outline-primary"
                                                 :href="route('admin.environmental-fee.proof', $collection)">
                                    <i class="bi bi-image me-1" aria-hidden="true"></i>See the payer's screenshot
                                </x-external-link>
                            </p>
                        @else
                            <p class="small text-muted-vd mb-3">
                                No screenshot was uploaded. Check the reference in GCash itself.
                            </p>
                        @endif

                        <div class="d-flex flex-wrap gap-2 border-top pt-3">
                            <x-confirm-form :id="'confirm-'.$collection->id"
                                            :action="route('admin.environmental-fee.settle', $collection)"
                                            title="Confirm this transfer arrived?"
                                            :message="'Say this only once you have seen '.$collection->gcash_reference.' in the GCash app for '.\App\Support\Money::peso($collection->amount, true).'. It will then count towards the municipality\'s totals.'"
                                            trigger="It arrived"
                                            confirm="Yes, I have seen it"
                                            icon="check2"
                                            variant="primary"
                                            trigger-class="btn btn-sm btn-primary">
                                <input type="hidden" name="arrived" value="1">
                                <div class="mt-3">
                                    <label class="form-label" :for="'note-'.$collection->id">
                                        Note <span class="text-muted-vd fw-normal small">(optional)</span>
                                    </label>
                                    <input type="text" class="form-control" name="note" maxlength="500">
                                </div>
                            </x-confirm-form>

                            <x-confirm-form :id="'reject-'.$collection->id"
                                            :action="route('admin.environmental-fee.settle', $collection)"
                                            title="Mark this as not received?"
                                            message="The receipt stays on record — a fee may well have been owed — but the money is not counted, so your office can chase it."
                                            trigger="Not received"
                                            confirm="Yes, it did not arrive"
                                            icon="x-lg"
                                            variant="danger"
                                            trigger-class="btn btn-sm btn-outline-danger">
                                <input type="hidden" name="arrived" value="0">
                                <div class="mt-3">
                                    <label class="form-label">Why not <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="note" rows="2"
                                              maxlength="500" required
                                              placeholder="No such reference in GCash; amount does not match; …"></textarea>
                                </div>
                            </x-confirm-form>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif

    @if ($decided->isNotEmpty())
        <h2 class="h5 mb-3">Recently checked</h2>

        <x-data-table label="Recently checked GCash transfers">
            <x-slot:head>
                <tr>
                    <th scope="col">GCash reference</th>
                    <th scope="col">Checkpoint</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Result</th>
                    <th scope="col">Checked by</th>
                </tr>
            </x-slot:head>

            @foreach ($decided as $collection)
                <tr>
                    <td class="font-monospace small">{{ $collection->gcash_reference }}</td>
                    <td class="small">{{ $collection->checkpoint?->name }}</td>
                    <td>@peso($collection->amount, true)</td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $collection->payment_status->badge() }}">
                            {{ $collection->payment_status->label() }}
                        </span>
                        @if ($collection->verification_note)
                            <span class="d-block small text-muted-vd">
                                {{ $collection->verification_note }}
                            </span>
                        @endif
                    </td>
                    <td class="small">
                        {{ $collection->verifiedBy?->full_name }}
                        <span class="d-block text-muted-vd">
                            {{ $collection->verified_at?->format('M j, g:i A') }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
