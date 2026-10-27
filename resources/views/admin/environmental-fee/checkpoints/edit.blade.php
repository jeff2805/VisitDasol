@extends('layouts.admin')

@section('title', 'Edit checkpoint')

@section('content')
    <x-page-header title="Edit checkpoint" :subtitle="$checkpoint->name">
        <a class="btn btn-light border"
           href="{{ route('admin.environmental-fee.checkpoints.show', $checkpoint) }}">Cancel</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.environmental-fee.checkpoints.update', $checkpoint) }}"
          novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('admin.environmental-fee.checkpoints._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border"
               href="{{ route('admin.environmental-fee.checkpoints.show', $checkpoint) }}">Cancel</a>
        </div>
    </form>
@endsection
