@extends('layouts.admin')

@section('title', $official->full_name)

@section('content')
    <x-page-header :title="$official->full_name" :subtitle="'Barangay '.$official->barangay">
        <x-status-badge :status="$official->status" />
        <a class="btn btn-light border" href="{{ route('admin.barangay-officials.index') }}">Back</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Destinations they have written</h2>

                @if ($spots->isEmpty())
                    <p class="text-muted-vd mb-0">Nothing yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach ($spots as $spot)
                            <li class="d-flex flex-wrap justify-content-between gap-2 border-top py-2">
                                <span>
                                    <span class="fw-semibold d-block">{{ $spot->name }}</span>
                                    <span class="small text-muted-vd">{{ $spot->category?->name ?? '—' }}</span>
                                </span>
                                <span class="text-end">
                                    <x-status-badge :status="$spot->status" />
                                    @if ($spot->status === \App\Enums\DestinationStatus::Pending)
                                        <a class="btn btn-sm btn-primary d-block mt-1"
                                           href="{{ route('admin.spot-submissions.show', $spot) }}">Read it</a>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Account</h2>
                <dl class="row mb-3 small">
                    <dt class="col-5 text-muted-vd fw-normal">Email</dt>
                    <dd class="col-7 text-break"><a href="mailto:{{ $official->email }}">{{ $official->email }}</a></dd>

                    <dt class="col-5 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-7"><a href="tel:{{ $official->phone }}">{{ $official->phone }}</a></dd>

                    <dt class="col-5 text-muted-vd fw-normal">Email confirmed</dt>
                    <dd class="col-7">{{ $official->email_verified_at ? 'Yes' : 'Not yet' }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Last signed in</dt>
                    <dd class="col-7 mb-0">{{ $official->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</dd>
                </dl>

                {{-- Deactivating lives on the accounts screen, with every other
                     account, rather than being a second switch here that could
                     drift out of step with it. --}}
                <a class="btn btn-light border w-100"
                   href="{{ route('admin.users.show', $official) }}">Manage this account</a>
            </section>
        </div>
    </div>
@endsection
