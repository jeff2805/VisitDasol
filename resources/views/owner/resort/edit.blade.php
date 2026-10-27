@extends('layouts.owner')

@section('title', 'Edit resort')

@section('content')
    <x-page-header title="Edit your resort" :subtitle="$resort->name">
        <a class="btn btn-light border" href="{{ route('owner.resort.show') }}">Back to my resort</a>
    </x-page-header>

    @if ($resort->status === \App\Enums\ResortStatus::Active)
        <div class="alert alert-warning d-flex gap-2" role="status">
            <i class="bi bi-eye mt-1" aria-hidden="true"></i>
            <div>This listing is live. Changes you save appear to guests straight away and are recorded for the tourism office.</div>
        </div>
    @elseif ($resort->status === \App\Enums\ResortStatus::Rejected)
        <div class="alert alert-danger d-flex gap-2" role="alert">
            <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong class="d-block">This listing was not approved.</strong>
                {{ $resort->review_remarks }}
                Fix the points above, save, then send it back for review from your resort page.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('owner.resort.update') }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('owner.resort._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('owner.resort.show') }}">Cancel</a>
        </div>
    </form>
@endsection
