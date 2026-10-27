{{-- Local-only diagnostics page (Phase 1). Route: /_dev/setup --}}
@extends('layouts.public')

@section('title', 'Setup check')

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
@endpush

@section('content')
    <div class="container py-5">
        <x-page-header title="Setup check" subtitle="Confirms that PHP, MySQL, storage, and the frontend files are ready. Visible only while APP_ENV=local.">
            <a class="btn btn-outline-primary" href="{{ route('dev.setup') }}"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Run again</a>
        </x-page-header>

        <div class="row g-3 mb-4">
            @foreach ([
                ['pass', 'Passed', 'check-circle-fill'],
                ['warn', 'Warnings', 'exclamation-triangle-fill'],
                ['fail', 'Failed', 'x-octagon-fill'],
            ] as [$key, $label, $icon])
                <div class="col-sm-4">
                    {{-- The label is given as a slot so the icon can be
                         coloured per outcome. <x-stat> knows nothing about
                         pass and fail, and should not. --}}
                    <x-stat :value="$summary[$key]">
                        <x-slot:label>
                            <i class="bi bi-{{ $icon }} vd-check-icon--{{ $key }} me-1" aria-hidden="true"></i>{{ $label }}
                        </x-slot:label>
                    </x-stat>
                </div>
            @endforeach
        </div>

        @if ($summary['fail'] === 0)
            <div class="alert alert-success d-flex gap-2" role="status">
                <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
                <div>No failures. Review any warnings, then finish the visual checks below.</div>
            </div>
        @else
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
                <div>{{ $summary['fail'] }} {{ Str::plural('check', $summary['fail']) }} failed. Fix them before Phase 2.</div>
            </div>
        @endif

        @foreach ($groups as $groupName => $checks)
            <section class="vd-panel mb-4" aria-labelledby="group-{{ $loop->index }}">
                <h2 class="h5 px-3 pt-3 mb-2" id="group-{{ $loop->index }}">{{ $groupName }}</h2>
                <x-scroll-region label="Setup checks">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 3rem"><span class="visually-hidden">Result</span></th>
                                <th scope="col" style="width: 30%">Check</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checks as $check)
                                @php
                                    $icon = ['pass' => 'check-circle-fill', 'warn' => 'exclamation-triangle-fill', 'fail' => 'x-octagon-fill'][$check['status']];
                                    $word = ['pass' => 'Passed', 'warn' => 'Warning', 'fail' => 'Failed'][$check['status']];
                                @endphp
                                <tr>
                                    <td>
                                        <i class="bi bi-{{ $icon }} vd-check-icon vd-check-icon--{{ $check['status'] }}" aria-hidden="true"></i>
                                        <span class="visually-hidden">{{ $word }}</span>
                                    </td>
                                    <td class="fw-semibold">{{ $check['label'] }}</td>
                                    <td class="text-muted-vd">{{ $check['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-scroll-region>
            </section>
        @endforeach

        <section class="vd-panel p-3 mb-4" aria-labelledby="visual-checks">
            <h2 class="h5" id="visual-checks">Visual checks</h2>
            <p class="text-muted-vd">
                The map needs an internet connection for its tiles. The pin marks the municipal center from
                <code>config/visitdasol.php</code>, and the dashed box is the coordinate boundary.
            </p>

            <div id="setup-map" class="vd-map mb-3"
                 data-center='@json(config('visitdasol.map.center'))'
                 data-bounds='@json(config('visitdasol.map.bounds'))'
                 data-zoom="{{ config('visitdasol.map.zoom') }}"
                 data-min-zoom="{{ config('visitdasol.map.min_zoom') }}"
                 data-max-zoom="{{ config('visitdasol.map.max_zoom') }}"
                 data-tile-url="{{ config('visitdasol.map.tile_url') }}"
                 data-attribution="{{ config('visitdasol.map.attribution') }}"></div>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#setupModal">Test Bootstrap JavaScript</button>
                <a class="btn btn-outline-primary" href="{{ route('dev.preview.owner') }}">Owner layout</a>
                <a class="btn btn-outline-primary" href="{{ route('dev.preview.admin') }}">MTO layout</a>
                <a class="btn btn-outline-primary" href="{{ route('login') }}">Sign-in page</a>
                <a class="btn btn-outline-primary" href="{{ route('register.owner') }}">Owner application</a>
                <a class="btn btn-outline-secondary" href="{{ route('dev.preview.error', 404) }}">404 page</a>
                <a class="btn btn-outline-secondary" href="{{ route('dev.preview.error', 403) }}">403 page</a>
            </div>
        </section>
    </div>

    <div class="modal fade" id="setupModal" tabindex="-1" aria-labelledby="setupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="setupModalLabel">Bootstrap JavaScript works</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    This dialog opened, so <code>bootstrap.bundle.min.js</code> loaded from <code>public/vendor</code>.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="@vdasset('js/setup-check.js')"></script>
@endpush
