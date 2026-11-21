@extends('layouts.admin')

@section('title', 'About Page Management')

@section('content')
    <x-page-header title="About Page Management"
                   subtitle="What the public About page says about Dasol. Saving is not publishing.">
        {{-- A new tab, so the office does not lose the editor while they
             look. <x-external-link> is what says so out loud for anybody
             who cannot see the tab appear. --}}
        <x-external-link class="btn btn-light border" :href="route('admin.about.preview')">
            <i class="bi bi-eye me-1" aria-hidden="true"></i>Preview
        </x-external-link>
        <x-external-link class="btn btn-light border" :href="route('about')">
            View live page
        </x-external-link>
    </x-page-header>

    {{-- Said once, at the top, because it is the one thing about this
         screen that is not obvious: typing into a section changes nothing
         a visitor can see until Publish is pressed. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
        <span>
            Your changes are saved as a draft and stay private until you publish them.
            Use <strong>Preview</strong> to see how the page will look first.
        </span>
    </div>

    <div class="row g-3">
        @foreach ($sections as $row)
            @php($section = $row->section_key)

            <div class="col-12 col-md-6 col-xl-4">
                <article class="vd-panel p-4 h-100 d-flex flex-column">
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                        <h2 class="h6 mb-0">
                            <i class="bi bi-{{ $section->icon() }} me-1" aria-hidden="true"></i>
                            {{ $section->label() }}
                        </h2>

                        @if ($row->hasUnpublishedChanges())
                            <span class="vd-badge vd-badge--pending">Draft</span>
                        @elseif ($row->isPublished())
                            <span class="vd-badge vd-badge--active">Published</span>
                        @else
                            <span class="vd-badge vd-badge--draft">Default</span>
                        @endif
                    </div>

                    <p class="text-muted-vd small flex-grow-1">{{ $section->blurb() }}</p>

                    <p class="small text-muted-vd mb-3">
                        @if ($row->isPublished())
                            Published {{ $row->published_at->format('M j, Y g:i A') }}
                            @if ($row->publishedBy)
                                by {{ $row->publishedBy->full_name }}
                            @endif
                        @else
                            Showing the built-in wording.
                        @endif
                    </p>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.about.edit', $section->value) }}">
                            Edit
                        </a>

                        @if ($row->hasUnpublishedChanges())
                            <x-confirm-form
                                :id="'publish-'.$section->value"
                                :action="route('admin.about.publish', $section->value)"
                                method="PATCH"
                                title="Publish this section?"
                                message="Your draft will replace what visitors currently see on the About page."
                                trigger="Publish"
                                confirm="Yes, publish"
                                variant="primary"
                                trigger-class="btn btn-sm btn-outline-primary" />
                        @endif

                        @if ($row->isPublished())
                            <x-confirm-form
                                :id="'unpublish-'.$section->value"
                                :action="route('admin.about.unpublish', $section->value)"
                                method="PATCH"
                                title="Take this section off the page?"
                                message="Visitors will see the built-in wording instead. What you wrote is kept as a draft, so nothing is lost."
                                trigger="Unpublish"
                                confirm="Take it down"
                                variant="warning"
                                trigger-class="btn btn-sm btn-outline-secondary" />
                        @endif
                    </div>
                </article>
            </div>
        @endforeach
    </div>
@endsection
