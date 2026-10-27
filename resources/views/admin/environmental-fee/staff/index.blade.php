@extends('layouts.admin')

@section('title', 'Fee staff')

@section('content')
    <x-page-header title="Fee staff"
                   subtitle="The people who take the environmental fee and count it.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.index') }}">Fee settings</a>
        <a class="btn btn-primary" href="{{ route('admin.environmental-fee.staff.create') }}">
            <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add staff
        </a>
    </x-page-header>

    {{-- What this screen does and does not hand out, said before
         anybody starts adding people. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">Nobody here is given a password</strong>
            The office creates the account; the person sets their own password through
            a link that expires. You will never see it, and no password is ever sent by
            email. Switching an account off is done from
            <a href="{{ route('admin.users.index') }}">Accounts</a>, which also signs
            them out of anything they have open.
        </div>
    </div>

    @if ($staff->isEmpty())
        <x-empty-state icon="people" title="No fee staff yet"
                       message="Add a collector to take the fee at a checkpoint, or a cashier to count what the gates hand in." />
    @else
        <x-data-table>
            <x-slot:head>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Role</th>
                    <th scope="col">Employee no.</th>
                    <th scope="col">Checkpoints</th>
                    <th scope="col">Account</th>
                    <th scope="col" class="text-end">Manage</th>
                </tr>
            </x-slot:head>

            @foreach ($staff as $person)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $person->full_name }}</span>
                        <span class="small text-muted-vd">{{ $person->email }}</span>
                    </td>
                    <td>{{ $person->role->label() }}</td>
                    <td class="font-monospace small">{{ $person->employee_id ?? '—' }}</td>
                    <td class="small">
                        @forelse ($person->feeAssignments as $assignment)
                            <span class="d-block">
                                {{ $assignment->checkpoint?->name }}
                                <span class="text-muted-vd">
                                    &middot; Brgy. {{ $assignment->checkpoint?->barangay }}
                                </span>
                            </span>
                        @empty
                            <span class="text-muted-vd">
                                {{ $person->role->takesCheckpoints() ? 'Not on a gate yet' : '—' }}
                            </span>
                        @endforelse
                    </td>
                    <td>
                        <x-status-badge :status="$person->status" />

                        {{-- Whether they have ever actually signed in. An
                             account that was never set up looks identical to a
                             working one until somebody asks why they cannot
                             get in. --}}
                        @if ($person->email_verified_at === null)
                            <span class="d-block small text-muted-vd">Has not set a password</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('updateStaff', $person)
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('admin.environmental-fee.staff.edit', $person) }}">Edit</a>
                        @endcan

                        @can('inviteStaff', $person)
                            <form method="POST" class="d-inline"
                                  action="{{ route('admin.environmental-fee.staff.invite', $person) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light border">
                                    {{ $person->email_verified_at === null ? 'Send invitation' : 'Send reset link' }}
                                </button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $staff->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
