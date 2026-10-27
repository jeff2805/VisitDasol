@extends('layouts.admin')

@section('title', $staff->full_name)

@section('content')
    <x-page-header :title="$staff->full_name"
                   :subtitle="$staff->role->label()">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.staff.index') }}">Back to staff</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.environmental-fee.staff.update', $staff) }}" data-submit-once>
        @csrf
        @method('PUT')

        @include('admin.environmental-fee.staff._form')

        <div class="mt-4 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a class="btn btn-light border" href="{{ route('admin.environmental-fee.staff.index') }}">Cancel</a>
        </div>

        {{-- Said here because this is the screen where somebody would
             look for a delete button. --}}
        <p class="form-text mt-2">
            There is no way to delete a staff account. The receipts they wrote, the
            handovers they made and the audit entries naming them all point at it.
            To stop somebody signing in, deactivate them from
            <a href="{{ route('admin.users.index') }}">Accounts</a>.
        </p>
    </form>
@endsection
