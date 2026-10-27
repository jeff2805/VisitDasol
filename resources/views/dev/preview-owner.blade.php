{{-- Local-only preview of the resort owner layout. Sample content only; nothing here is saved. --}}
@extends('layouts.owner')

@section('title', 'Dashboard preview')

@section('content')
    <x-page-header title="Good morning" subtitle="Layout preview with sample content. Real figures arrive in Phases 6 to 12.">
        <span class="btn btn-primary disabled" aria-disabled="true"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add room type</span>
    </x-page-header>

    <div class="alert alert-warning d-flex gap-2" role="status">
        <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
        <div><strong>Your resort is waiting for review.</strong> The Municipal Tourism Office will check your details. Guests cannot see or book it yet.</div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([['4', 'Requests to review'], ['12', 'Confirmed stays this month'], ['3', 'Room types'], ['68%', 'Occupancy this month']] as [$value, $label])
            <div class="col-6 col-xl-3">
                <x-stat :value="$value" :label="$label" />
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <section class="vd-panel">
                <h2 class="h5 px-3 pt-3">Latest reservations (sample)</h2>
                <x-scroll-region label="Latest reservations">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Reference</th>
                                <th scope="col">Guest</th>
                                <th scope="col">Stay</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                ['VD-20261010-A8F2K', 'Sample Guest A', 'Oct 10 to 13', '₱7,500.00', 'pending'],
                                ['VD-20261002-Q7M3T', 'Sample Guest B', 'Oct 2 to 4', '₱5,000.00', 'confirmed'],
                                ['VD-20260920-H2W9P', 'Sample Guest C', 'Sep 20 to 21', '₱2,500.00', 'completed'],
                                ['VD-20260918-K4N6R', 'Sample Guest D', 'Sep 18 to 19', '₱2,500.00', 'cancelled'],
                            ] as [$ref, $guest, $stay, $total, $status])
                                <tr>
                                    <td class="font-monospace small">{{ $ref }}</td>
                                    <td>{{ $guest }}</td>
                                    <td>{{ $stay }}</td>
                                    <td class="text-end">{{ $total }}</td>
                                    <td><span class="vd-badge vd-badge--{{ $status }}">{{ ucfirst($status) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-scroll-region>
            </section>
        </div>

        <div class="col-xl-4">
            <x-empty-state icon="images" title="No gallery photos yet" message="Add up to 10 photos so guests can see your rooms and beach.">
                <span class="btn btn-outline-primary disabled" aria-disabled="true">Upload photos</span>
            </x-empty-state>
        </div>
    </div>

    <section class="vd-panel p-3 mt-4">
        <h2 class="h5">Form controls (sample)</h2>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="sample-name">Room type name</label>
                <input class="form-control" id="sample-name" value="Seaview Deluxe">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="sample-barangay">Barangay</label>
                <select class="form-select" id="sample-barangay">
                    @foreach (config('visitdasol.barangays') as $barangay)
                        <option @selected($barangay === 'Tambobong')>{{ $barangay }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="sample-price">Price per night</label>
                <div class="input-group has-validation">
                    <span class="input-group-text">₱</span>
                    <input class="form-control is-invalid" id="sample-price" value="-100" aria-describedby="sample-price-error">
                    <div class="invalid-feedback" id="sample-price-error">Enter a price of ₱1 or more.</div>
                </div>
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="sample-active" checked>
                    <label class="form-check-label" for="sample-active">Guests can book this room type</label>
                </div>
            </div>
        </div>
    </section>
@endsection
