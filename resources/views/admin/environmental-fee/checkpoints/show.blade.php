@extends('layouts.admin')

@section('title', $checkpoint->name)

@section('content')
    <x-page-header :title="$checkpoint->name" :subtitle="'Brgy. '.$checkpoint->barangay">
        <a class="btn btn-light border"
           href="{{ route('admin.environmental-fee.checkpoints.index') }}">All checkpoints</a>
        <a class="btn btn-light border"
           href="{{ route('admin.environmental-fee.checkpoints.edit', $checkpoint) }}">Edit</a>

        <x-confirm-form id="toggle-checkpoint"
                        :action="route('admin.environmental-fee.checkpoints.status', $checkpoint)"
                        :title="$checkpoint->active ? 'Close this checkpoint?' : 'Open this checkpoint?'"
                        :message="$checkpoint->active
                            ? 'Nothing more can be collected here. Receipts already recorded are not affected.'
                            : 'Assigned collectors will be able to record fees here again.'"
                        :trigger="$checkpoint->active ? 'Close it' : 'Open it'"
                        :confirm="$checkpoint->active ? 'Yes, close it' : 'Yes, open it'"
                        :variant="$checkpoint->active ? 'danger' : 'primary'"
                        :trigger-class="'btn '.($checkpoint->active ? 'btn-outline-danger' : 'btn-primary')" />
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">The checkpoint</h2>

                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">State</dt>
                    <dd class="col-sm-8">
                        <span class="vd-badge vd-badge--{{ $checkpoint->active ? 'active' : 'inactive' }}">
                            {{ $checkpoint->active ? 'Open' : 'Closed' }}
                        </span>
                    </dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Barangay</dt>
                    <dd class="col-sm-8">{{ $checkpoint->barangay }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Coordinates</dt>
                    <dd class="col-sm-8 font-monospace small">
                        {{ $checkpoint->latitude }}, {{ $checkpoint->longitude }}
                    </dd>

                    @if ($checkpoint->description)
                        <dt class="col-sm-4 text-muted-vd fw-normal">Notes</dt>
                        <dd class="col-sm-8 mb-0">{{ $checkpoint->description }}</dd>
                    @endif
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-1">Who may collect here</h2>
                <p class="text-muted-vd small mb-3">
                    Authority is given one checkpoint at a time. Somebody trusted at this
                    gate is not thereby trusted at every gate in Dasol.
                </p>

                @php($active = $checkpoint->assignments->where('active', true))

                @if ($active->isEmpty())
                    <div class="alert alert-warning py-2 small" role="status">
                        Nobody is assigned, so nothing can be collected here even while the
                        fee is switched on.
                    </div>
                @else
                    <ul class="list-unstyled mb-3">
                        @foreach ($active as $assignment)
                            <li class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top py-2">
                                <span>
                                    <span class="d-block fw-semibold">{{ $assignment->user->full_name }}</span>
                                    <span class="small text-muted-vd">
                                        {{ $assignment->user->role->label() }}
                                        &middot; assigned by {{ $assignment->assignedBy?->full_name }}
                                        on {{ $assignment->created_at->format('M j, Y') }}
                                    </span>
                                </span>

                                <x-confirm-form :id="'withdraw-'.$assignment->id"
                                                :action="route('admin.environmental-fee.collectors.withdraw', [$checkpoint, $assignment])"
                                                method="DELETE"
                                                title="Withdraw this collector?"
                                                :message="$assignment->user->full_name.' will no longer be able to record fees at '.$checkpoint->name.'. Receipts they already recorded are not affected.'"
                                                trigger="Withdraw"
                                                confirm="Yes, withdraw"
                                                variant="danger"
                                                trigger-class="btn btn-sm btn-outline-danger" />
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($candidates->isNotEmpty())
                    <form method="POST"
                          action="{{ route('admin.environmental-fee.collectors.assign', $checkpoint) }}"
                          class="d-flex flex-wrap gap-2 align-items-end border-top pt-3">
                        @csrf

                        <div class="flex-grow-1" style="min-width: 14rem">
                            <label class="form-label" for="field-user-id">Assign somebody</label>
                            <select class="form-select @error('user_id') is-invalid @enderror"
                                    id="field-user-id" name="user_id" required>
                                <option value="" disabled selected>Choose a person</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->full_name }} — {{ $candidate->role->label() }}@if ($candidate->barangay) ({{ $candidate->barangay }})@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Assign</button>
                    </form>
                @endif

                @php($withdrawn = $checkpoint->assignments->where('active', false))

                @if ($withdrawn->isNotEmpty())
                    {{-- Kept visible: a withdrawn assignment explains receipts
                         that already carry that collector's name. --}}
                    <details class="vd-hints mt-3">
                        <summary>Withdrawn ({{ $withdrawn->count() }})</summary>
                        <ul class="list-unstyled mb-0 mt-2 small text-muted-vd">
                            @foreach ($withdrawn as $assignment)
                                <li>{{ $assignment->user->full_name }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        </div>

        <div class="col-lg-5">
            <aside class="vd-panel p-4">
                <h2 class="h6 mb-3">On the map</h2>
                <x-location-map :latitude="$checkpoint->latitude" :longitude="$checkpoint->longitude"
                                :label="$checkpoint->name" style="height: 16rem" />
                <p class="form-text mb-0 mt-2">
                    Visitors see this pin only while a fee is actually being collected.
                </p>
            </aside>
        </div>
    </div>
@endsection
