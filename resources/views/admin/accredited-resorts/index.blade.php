@extends('layouts.admin')

@section('title', 'Accredited resorts')

@section('content')
    <x-page-header title="Accredited resorts"
                   subtitle="The office's official accreditation register. Applications are checked against this list automatically — being on it is not an approval." />

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <x-stat :value="$total" label="Records on the list" icon="list-check" />
        </div>
        <div class="col-sm-6 col-lg-3">
            <x-stat :value="$current" label="Currently accredited" icon="patch-check"
                    note="Active, and not past its validity date." />
        </div>
    </div>

    {{-- Upload -------------------------------------------------------- --}}

    <section class="vd-panel p-4 mb-4">
        <h2 class="h5 mb-1">Upload the accredited resorts list</h2>
        <p class="text-muted-vd small mb-3">
            An Excel (.xlsx) or CSV file. You will see what it changes before anything is saved.
        </p>

        <form method="POST" action="{{ route('admin.accredited-resorts.preview') }}"
              enctype="multipart/form-data" data-submit-once>
            @csrf

            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="accreditation-file">Spreadsheet</label>
                    <input type="file" class="form-control @error('file') is-invalid @enderror"
                           id="accreditation-file" name="file" accept=".xlsx,.csv" required
                           @error('file') aria-invalid="true" aria-describedby="accreditation-file-error" @enderror>
                    @error('file')
                        <div class="invalid-feedback" id="accreditation-file-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1" aria-hidden="true"></i>
                        Read the file
                    </button>
                </div>
            </div>
        </form>

        {{-- What the file has to contain. Written out rather than left to
             a sample download, because the most common failed upload is a
             sheet whose first row is a title instead of column names. --}}
        <details class="vd-hints mt-3">
            <summary>What should the file contain?</summary>
            <div class="vd-hints__list">
                <p class="small mb-2">
                    The first row must name the columns. Only <strong>Resort Name</strong> is required.
                </p>
                <x-scroll-region label="Example of the file layout">
                    <table class="table table-sm small mb-2">
                        <thead>
                            <tr>
                                <th scope="col">Resort Name</th>
                                <th scope="col">Owner</th>
                                <th scope="col">Barangay</th>
                                <th scope="col">Accreditation No.</th>
                                <th scope="col">Status</th>
                                <th scope="col">Valid Until</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>ABC Resort</td>
                                <td>Juan Dela Cruz</td>
                                <td>Tambobong</td>
                                <td>ACC-001</td>
                                <td>Active</td>
                                <td>2027-06-30</td>
                            </tr>
                        </tbody>
                    </table>
                </x-scroll-region>
                <p class="small text-muted-vd mb-0">
                    Status may be Active, Expired or Suspended, and defaults to Active when the
                    column is missing. A record that is already on the list is updated rather than
                    added twice.
                </p>
            </div>
        </details>
    </section>

    {{-- The register ---------------------------------------------------- --}}

    <form method="GET" class="vd-panel p-3 mb-4">
        <div class="row g-2 align-items-end">
            <div class="col-sm-5">
                <label class="form-label" for="q">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $search }}"
                       placeholder="Resort, owner, barangay or number">
            </div>

            <div class="col-sm-4">
                <label class="form-label" for="status">Accreditation status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All ({{ $total }})</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected($status?->value === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-3 d-grid">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </div>
    </form>

    @if ($records->isEmpty())
        <x-empty-state icon="patch-question"
                       title="{{ $total === 0 ? 'No accreditation records yet' : 'Nothing matches those filters' }}"
                       message="{{ $total === 0
                            ? 'Upload the office list above. Until then, every application will be reported as not listed.'
                            : 'Try a different search or clear the filters.' }}" />
    @else
        <x-data-table label="Accredited resorts">
            <x-slot:head>
                <tr>
                    <x-sort-header :sort="$sort" column="resort">Resort</x-sort-header>
                    <x-sort-header :sort="$sort" column="barangay">Barangay</x-sort-header>
                    <x-sort-header :sort="$sort" column="number">Accreditation no.</x-sort-header>
                    <th scope="col">Status</th>
                    <x-sort-header :sort="$sort" column="valid">Valid until</x-sort-header>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($records as $record)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $record->resort_name }}</span>
                        @if (filled($record->owner_name))
                            <span class="small text-muted-vd">{{ $record->owner_name }}</span>
                        @endif
                    </td>
                    <td>{{ $record->barangay ?? '—' }}</td>
                    <td>{{ $record->accreditation_no ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $record->accreditation_status->badgeClass() }}">
                            {{ $record->accreditation_status->label() }}
                        </span>
                    </td>
                    <td>
                        @if ($record->valid_until === null)
                            <span class="text-muted-vd">—</span>
                        @else
                            {{ $record->valid_until->format('M j, Y') }}
                            @if ($record->valid_until->isPast())
                                <span class="d-block small text-danger">Expired</span>
                            @endif
                        @endif
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#accredited-{{ $record->id }}">
                            Edit
                        </button>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $records->links() }}</x-slot:below>
        </x-data-table>

        {{-- One editing dialog per row. The office's commonest change is a
             single renewal or suspension between uploads, and re-uploading
             the whole spreadsheet for one date is not reasonable. --}}
        @foreach ($records as $record)
            <x-accredited-resort-form :record="$record" :statuses="$statuses" />
        @endforeach
    @endif
@endsection
