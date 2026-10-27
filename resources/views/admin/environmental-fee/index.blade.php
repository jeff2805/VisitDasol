@extends('layouts.admin')

@section('title', 'Environmental fee')

@section('content')
    <x-page-header title="Environmental fee"
                   subtitle="A municipal collection, recorded separately from resort bookings.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.checkpoints.index') }}">
            <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Checkpoints
        </a>
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.reports') }}">
            <i class="bi bi-receipt me-1" aria-hidden="true"></i>Collections
        </a>
        <a class="btn btn-primary" href="{{ route('admin.environmental-fee.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Record a configuration
        </a>
    </x-page-header>

    {{-- The state of the module, said plainly at the top. Switched off
         with no rate is where it starts, and an officer arriving here
         for the first time should not have to work that out. --}}
    @if ($blocked)
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">Nothing is being collected</strong>
                {{ $blocked }}
            </div>
        </div>
    @else
        <div class="alert alert-success d-flex gap-2" role="status">
            <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">
                    Collection is on &mdash; @peso($active->amount, true) {{ $active->scope?->label() }}
                </strong>
                In force since {{ $active->effective_from->format('M j, Y') }}@if ($active->effective_until), until {{ $active->effective_until->format('M j, Y') }}@endif.
                {{ $checkpointCount }} active {{ Str::plural('checkpoint', $checkpointCount) }}.
            </div>
        </div>
    @endif

    {{-- What a total here does and does not mean. Worth saying on the
         office's own screen, not only on the report. --}}
    <p class="text-muted-vd small">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        This module records fees collected at municipal checkpoints. It is kept entirely
        separate from resort bookings: it never changes a room rate, a deposit or a
        booking total, and nothing here is resort income.
    </p>

    @if ($policies->isEmpty())
        <x-empty-state icon="cash-stack" title="No fee has been configured"
                       message="Record what the ordinance says first. Saving a configuration does not start collection — you switch that on separately." />
    @else
        <x-data-table label="Environmental fee configurations">
            <x-slot:head>
                <tr>
                    <th scope="col">Amount</th>
                    <th scope="col">Applies to</th>
                    <th scope="col">In force</th>
                    <th scope="col">Authority</th>
                    <th scope="col">Recorded by</th>
                    <th scope="col">Receipts</th>
                    <th scope="col">State</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach ($policies as $policy)
                    <tr>
                        <td class="fw-semibold">
                            @if ($policy->amount === null)
                                <span class="text-muted-vd">Not set</span>
                            @else
                                @peso($policy->amount, true)
                            @endif
                        </td>
                        <td>{{ $policy->scope?->label() ?? '—' }}</td>
                        <td>
                            {{ $policy->effective_from?->format('M j, Y') ?? '—' }}
                            @if ($policy->effective_until)
                                <span class="text-muted-vd">to {{ $policy->effective_until->format('M j, Y') }}</span>
                            @elseif ($policy->effective_from)
                                <span class="text-muted-vd">onwards</span>
                            @endif

                            {{-- An end date that has passed looks exactly like
                                 one that has not, and a switched-on policy that
                                 collects nothing is the hardest state to spot.
                                 Named here rather than left to be worked out
                                 from two dates and today's. --}}
                            @if ($policy->effective_until && $policy->effective_until->isPast())
                                <span class="d-block">
                                    <span class="vd-badge vd-badge--inactive">Ended</span>
                                </span>
                            @elseif ($policy->effective_from && $policy->effective_from->isFuture())
                                <span class="d-block">
                                    <span class="vd-badge vd-badge--pending">Not started</span>
                                </span>
                            @endif
                        </td>
                        <td class="small">{{ $policy->authority_reference ?? '—' }}</td>
                        <td class="small">
                            {{ $policy->createdBy?->full_name }}
                            <span class="d-block text-muted-vd">{{ $policy->created_at->format('M j, Y') }}</span>
                        </td>
                        <td>{{ $policy->collections_count }}</td>
                        <td>
                            @if ($policy->enabled)
                                <span class="vd-badge vd-badge--active">On</span>
                                @if ($policy->enabledBy)
                                    <span class="d-block small text-muted-vd">
                                        by {{ $policy->enabledBy->full_name }},
                                        {{ $policy->enabled_at?->format('M j, Y') }}
                                    </span>
                                @endif
                            @else
                                <span class="vd-badge vd-badge--inactive">Off</span>
                            @endif
                        </td>
                        <td class="text-end">
                            {{-- Starting collection is its own decision, so it
                                 asks before it happens and says what follows. --}}
                            <x-confirm-form :id="'toggle-fee-'.$policy->id"
                                            :action="route('admin.environmental-fee.toggle', $policy)"
                                            :title="$policy->enabled ? 'Stop collecting this fee?' : 'Start collecting this fee?'"
                                            :message="$policy->enabled
                                                ? 'Collectors will no longer be able to record anything against it. Receipts already recorded are not affected.'
                                                : 'Assigned collectors will be able to record this fee at active checkpoints from now on. Your name and the time are recorded against this decision.'"
                                            :trigger="$policy->enabled ? 'Switch off' : 'Switch on'"
                                            :confirm="$policy->enabled ? 'Yes, stop collecting' : 'Yes, start collecting'"
                                            :variant="$policy->enabled ? 'danger' : 'primary'"
                                            trigger-class="btn btn-sm btn-outline-primary" />

                            {{-- Changing when it stops, and only that.

                                 An end date that passes takes the fee out of
                                 force and visitors stop being told about it,
                                 which is correct — but until now the only way
                                 back was a whole new policy. That is the right
                                 answer when the RATE changes and overkill when
                                 the council simply extended the same ordinance.

                                 The amount is deliberately absent from this
                                 form. Receipts have been written at it. --}}
                            <details class="vd-fee-extend">
                                <summary class="btn btn-sm btn-light border mt-1">
                                    {{ $policy->effective_until ? 'Change end date' : 'Set an end date' }}
                                </summary>

                                <form method="POST"
                                      action="{{ route('admin.environmental-fee.extend', $policy) }}"
                                      class="vd-fee-extend__form">
                                    @csrf
                                    @method('PATCH')

                                    <label class="form-label small"
                                           for="field-until-{{ $policy->id }}">Stops applying</label>
                                    <input type="date" class="form-control form-control-sm"
                                           id="field-until-{{ $policy->id }}"
                                           name="effective_until"
                                           min="{{ $policy->effective_from->toDateString() }}"
                                           value="{{ $policy->effective_until?->toDateString() }}">

                                    <p class="form-text mb-2">
                                        Leave it empty to run until you switch it off.
                                        The rate stays at @peso($policy->amount, true) &mdash; a
                                        different rate is a new configuration, so the receipts
                                        already written keep their own.
                                    </p>

                                    <button type="submit" class="btn btn-sm btn-primary w-100">Save dates</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @endforeach

            <x-slot:below>{{ $policies->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
