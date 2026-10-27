@extends('layouts.admin')

@section('title', 'Edit announcement')

@section('content')
    <x-page-header title="Edit announcement" :subtitle="$announcement->title">
        @if ($announcement->isPublished())
            <x-external-link class="btn btn-outline-primary" :href="route('announcements.show', $announcement)">
                View public page
            </x-external-link>
        @endif
        <a class="btn btn-light border" href="{{ route('admin.announcements.index') }}">Back to list</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.announcements.update', $announcement) }}" enctype="multipart/form-data" novalidate data-submit-once>
        @csrf
        @method('PUT')

        @include('admin.announcements._form')

        <div class="d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
            <a class="btn btn-light border" href="{{ route('admin.announcements.index') }}">Cancel</a>
        </div>
    </form>

    <section class="vd-panel p-4 mt-4">
        <h2 class="h5 mb-3">Publishing</h2>

        <div class="d-flex flex-wrap gap-2">
            @can('publish', $announcement)
                <form method="POST" action="{{ route('admin.announcements.publish', $announcement) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-primary">Publish now</button>
                </form>
            @endcan

            @can('unpublish', $announcement)
                <x-confirm-form id="unpublish-announcement"
                                :action="route('admin.announcements.unpublish', $announcement)"
                                title="Take this announcement down?"
                                message="It becomes a draft again and visitors will no longer see it. Nothing is deleted."
                                trigger="Take down"
                                confirm="Yes, take it down"
                                variant="danger"
                                trigger-class="btn btn-outline-danger" />
            @endcan

            @can('feature', $announcement)
                <form method="POST" action="{{ route('admin.announcements.feature', $announcement) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-light border">
                        <i class="bi bi-pin-angle{{ $announcement->is_featured ? '-fill' : '' }} me-1" aria-hidden="true"></i>
                        {{ $announcement->is_featured ? 'Unpin from the top' : 'Pin to the top' }}
                    </button>
                </form>
            @endcan
        </div>

        <p class="form-text mb-0 mt-3">
            To publish at a set time, put the date and time above and press Publish now: it stays hidden until then.
        </p>
    </section>
@endsection
