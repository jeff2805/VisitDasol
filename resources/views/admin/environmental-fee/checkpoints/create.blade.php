@extends('layouts.admin')

@section('title', 'Add a checkpoint')

@section('content')
    <x-page-header title="Add a checkpoint"
                   subtitle="A place the municipality collects the environmental fee.">
        <a class="btn btn-light border" href="{{ route('admin.environmental-fee.checkpoints.index') }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.environmental-fee.checkpoints.store') }}" novalidate data-submit-once>
        @csrf

        @include('admin.environmental-fee.checkpoints._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Add checkpoint</button>
            <a class="btn btn-light border" href="{{ route('admin.environmental-fee.checkpoints.index') }}">Cancel</a>
        </div>
    </form>
@endsection
