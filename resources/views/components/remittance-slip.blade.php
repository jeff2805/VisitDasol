{{--
    One handover, as the collector and the office both read it.

    Shared so the two sides cannot drift apart: a slip that said one
    thing at the gate and another in the office would be the one
    document nobody could reconcile against.

    THREE FIGURES, SHOWN SEPARATELY AND NEVER MERGED:
      expected  what the receipts add up to, summed by the server
      declared  what the collector said they were handing over
      counted   what the office actually counted

    live is the expected figure recalculated now. If a receipt has been
    voided since submission the two differ, and that is said out loud
    rather than quietly resolved in favour of either.
--}}
@props(['remittance', 'receipts', 'live' => null])

@php
    $declaredVariance = $remittance->declaredVariance();
    $variance = $remittance->variance();

    // Whether the receipts have moved under the handover's feet.
    $drifted = $live !== null
        && (int) round((float) $live['amount'] * 100)
            !== (int) round((float) $remittance->expected_amount * 100);
@endphp

<div class="vd-slip">
    <header class="vd-slip__head">
        <div>
            <h2 class="vd-slip__ref">{{ $remittance->reference }}</h2>
            <p class="vd-slip__where mb-0">
                {{ $remittance->checkpoint?->name }}
                @if ($remittance->checkpoint?->barangay)
                    &middot; Brgy. {{ $remittance->checkpoint->barangay }}
                @endif
            </p>
        </div>
        <x-status-badge :status="$remittance->status" />
    </header>

    <p class="vd-slip__meaning">
        {{ $remittance->status->description() }}
    </p>

    <dl class="row vd-slip__details">
        <dt class="col-sm-4 text-muted-vd fw-normal">Collector</dt>
        <dd class="col-sm-8">
            {{ $remittance->collector?->first_name }} {{ $remittance->collector?->last_name }}
        </dd>

        <dt class="col-sm-4 text-muted-vd fw-normal">Period</dt>
        <dd class="col-sm-8">
            {{ $remittance->period_start->format('M j, Y') }} to
            {{ $remittance->period_end->format('M j, Y') }}
        </dd>

        <dt class="col-sm-4 text-muted-vd fw-normal">Handed in</dt>
        <dd class="col-sm-8">{{ $remittance->submitted_at?->format('M j, Y g:i A') }}</dd>

        @if ($remittance->reviewed_at)
            <dt class="col-sm-4 text-muted-vd fw-normal">Counted by</dt>
            <dd class="col-sm-8">
                {{ $remittance->reviewedBy?->first_name }} {{ $remittance->reviewedBy?->last_name }}
                <span class="text-muted-vd">on {{ $remittance->reviewed_at->format('M j, Y g:i A') }}</span>
            </dd>
        @endif
    </dl>

    {{-- The money. Side by side, because the comparison IS the
         document — a single total would hide the only thing worth
         looking at. --}}
    <dl class="vd-remit-figures vd-slip__figures">
        <div>
            <dt>Receipts</dt>
            <dd>{{ $remittance->expected_receipts }}</dd>
        </div>
        <div>
            <dt>Expected</dt>
            <dd>@peso($remittance->expected_amount, true)</dd>
        </div>
        <div>
            <dt>Declared</dt>
            <dd>@peso($remittance->declared_amount, true)</dd>
        </div>
        <div>
            <dt>Counted</dt>
            <dd>
                @if ($remittance->counted_amount === null)
                    <span class="text-muted-vd">&mdash;</span>
                @else
                    @peso($remittance->counted_amount, true)
                @endif
            </dd>
        </div>
    </dl>

    {{-- Differences. Shown only when there is one: a row reading
         "Difference ₱0.00" on every agreeing slip trains people to
         stop reading the line. --}}
    @if ((int) round((float) $declaredVariance * 100) !== 0)
        <p class="vd-slip__variance vd-slip__variance--declared">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>
                <strong>Declared is @peso(abs((float) $declaredVariance), true)
                    {{ (float) $declaredVariance < 0 ? 'short of' : 'over' }}
                    the receipts.</strong>
                The collector said so when handing in; the office's own count is what settles it.
            </span>
        </p>
    @endif

    @if ($variance !== null && (int) round((float) $variance * 100) !== 0)
        <p class="vd-slip__variance vd-slip__variance--counted">
            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
            <span>
                <strong>Counted is @peso(abs((float) $variance), true)
                    {{ (float) $variance < 0 ? 'short of' : 'over' }}
                    the receipts.</strong>
                This difference stays on the record until somebody accounts for it.
            </span>
        </p>
    @endif

    @if ($drifted)
        <p class="vd-slip__variance vd-slip__variance--drift">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            <span>
                <strong>The receipts have changed since this was handed in.</strong>
                They now add up to @peso($live['amount'], true) across {{ $live['receipts'] }}
                {{ Str::plural('receipt', $live['receipts']) }}, usually because one was
                voided. The figures above are what was claimed at the time and are left as they were.
            </span>
        </p>
    @endif

    @if (filled($remittance->collector_note))
        <div class="vd-slip__note">
            <strong class="d-block">From the collector</strong>
            {{ $remittance->collector_note }}
        </div>
    @endif

    @if (filled($remittance->review_note))
        <div class="vd-slip__note vd-slip__note--office">
            <strong class="d-block">From the office</strong>
            {{ $remittance->review_note }}
        </div>
    @endif

    {{-- The receipts behind the figure. Reference and amount only: who
         paid a fee at a gate is nobody's business on a handover slip,
         and the module never recorded a payer in the first place. --}}
    <h3 class="vd-slip__subhead">
        Cash receipts in this period ({{ $receipts->count() }})
    </h3>

    @if ($receipts->isEmpty())
        <p class="text-muted-vd mb-0">
            No cash receipts remain in this period. If there were some when this was
            handed in, they have since been voided.
        </p>
    @else
        {{-- x-scroll-region, not a bare .table-responsive: a sideways
             scroll has to be reachable by keyboard, which the project
             checks for in UiConsistencyTest. --}}
        <x-scroll-region label="Cash receipts in this period">
            <table class="table table-sm vd-slip__table mb-0">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Receipt</th>
                        <th scope="col">Taken</th>
                        <th scope="col" class="text-end">People</th>
                        <th scope="col" class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($receipts as $receipt)
                        <tr>
                            <td class="font-monospace small">{{ $receipt->reference }}</td>
                            <td class="font-monospace small">{{ $receipt->receipt_number }}</td>
                            <td class="small">{{ $receipt->collected_at?->format('M j, g:i A') }}</td>
                            <td class="text-end">{{ $receipt->quantity }}</td>
                            <td class="text-end">@peso($receipt->amount, true)</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-scroll-region>
    @endif

    {{-- What this document is not. Printed on the slip itself, because
         the slip is what leaves the building. --}}
    <p class="vd-slip__disclaimer">
        This records a handover between two named people. It is not a bank deposit and
        not proof that the money reached the municipal treasury. Visit Dasol cannot see
        a bank account. Municipal environmental fees are separate from resort bookings
        and are no part of any resort's income.
    </p>
</div>
