@extends('layouts.admin')

@section('title', $user->full_name)

@section('content')
    <x-page-header :title="$user->full_name" :subtitle="$user->role->label()">
        <a class="btn btn-outline-primary" href="{{ route('admin.users.index') }}">Back to accounts</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Details</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted-vd fw-normal">Email</dt>
                    <dd class="col-sm-8"><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-sm-8">{{ $user->phone ?? '—' }}</dd>

                    @if ($user->isTourist())
                        <dt class="col-sm-4 text-muted-vd fw-normal">Travelling from</dt>
                        <dd class="col-sm-8">{{ collect([$user->home_city, $user->home_country])->filter()->implode(', ') ?: '—' }}</dd>
                    @endif

                    <dt class="col-sm-4 text-muted-vd fw-normal">Registered</dt>
                    <dd class="col-sm-8">{{ $user->created_at->format('M j, Y') }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Last sign-in</dt>
                    <dd class="col-sm-8">{{ $user->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Status</dt>
                    <dd class="col-sm-8 mb-0"><x-status-badge :status="$user->status" /></dd>
                </dl>
            </section>

            @if ($user->isResortOwner())
                <section class="vd-panel p-4">
                    <h2 class="h5 mb-3">Business and listing</h2>
                    <dl class="row mb-3">
                        <dt class="col-sm-4 text-muted-vd fw-normal">Business</dt>
                        <dd class="col-sm-8">{{ $user->ownerProfile?->business_name ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted-vd fw-normal">Position</dt>
                        <dd class="col-sm-8">{{ $user->ownerProfile?->position ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted-vd fw-normal">Application</dt>
                        <dd class="col-sm-8 mb-0">
                            <a href="{{ route('admin.owner-applications.show', $user) }}">Open the application</a>
                        </dd>
                    </dl>

                    @if ($user->resorts->isEmpty())
                        <p class="text-muted-vd mb-0">No resort submitted yet.</p>
                    @else
                        <ul class="list-unstyled mb-0">
                            @foreach ($user->resorts as $resort)
                                <li class="d-flex justify-content-between align-items-center gap-2 border-top py-2">
                                    <span>{{ $resort->name }}</span>
                                    <x-status-badge :status="$resort->status" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Account access</h2>

                @can('deactivate', $user)
                    <p class="text-muted-vd">
                        Deactivating signs this person out everywhere and blocks sign-in.
                        @if ($user->isResortOwner()) Their resort stops appearing publicly straight away. @endif
                    </p>
                    <x-confirm-form id="deactivate-user"
                                    :action="route('admin.users.deactivate', $user)"
                                    title="Deactivate this account?"
                                    :message="$user->full_name.' will be signed out and will not be able to sign in.'"
                                    trigger="Deactivate account"
                                    confirm="Yes, deactivate"
                                    icon="pause-circle"
                                    variant="danger"
                                    trigger-class="btn btn-outline-danger">
                        <div class="mt-3">
                            <label class="form-label" for="reason">Note for the record</label>
                            <input type="text" class="form-control" id="reason" name="reason" maxlength="255"
                                   placeholder="Optional, kept in the activity log">
                        </div>
                    </x-confirm-form>
                @elsecan('activate', $user)
                    <p class="text-muted-vd">This account cannot sign in at the moment.</p>
                    <x-confirm-form id="activate-user"
                                    :action="route('admin.users.activate', $user)"
                                    title="Reactivate this account?"
                                    :message="$user->full_name.' will be able to sign in again.'"
                                    trigger="Reactivate account"
                                    confirm="Yes, reactivate"
                                    icon="play-circle"
                                    variant="primary" />
                @else
                    <p class="text-muted-vd mb-0">
                        @if ($user->is(auth()->user()))
                            This is your own account, so it cannot be changed from here.
                        @elseif ($user->isSuperAdmin())
                            Tourism office accounts cannot be changed from here.
                        @else
                            This application is still waiting for a decision.
                            <a href="{{ route('admin.owner-applications.show', $user) }}">Review it</a>.
                        @endif
                    </p>
                @endcan
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Recent activity</h2>
                @if ($history->isEmpty())
                    <p class="text-muted-vd mb-0">Nothing recorded yet.</p>
                @else
                    <ul class="list-unstyled mb-0 small">
                        @foreach ($history as $entry)
                            <li class="border-top py-2">
                                <span class="d-block">{{ $entry->description }}</span>
                                <span class="text-muted-vd">
                                    {{ $entry->created_at->format('M j, Y g:i A') }}
                                    @if ($entry->user) by {{ $entry->user->full_name }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
