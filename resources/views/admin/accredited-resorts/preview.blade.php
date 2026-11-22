@extends('layouts.admin')

@section('title', 'Check the upload')

@section('content')
    <x-page-header title="Check the upload"
                   :subtitle="'Nothing has been saved yet. This is what ' . $filename . ' would do to the accreditation list.'" />

    @php
        $counts = $preview['counts'];
        $willWrite = $counts['create'] + $counts['update'];
    @endphp

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <x-stat :value="$counts['create']" label="To be added" icon="plus-circle" />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat :value="$counts['update']" label="To be updated" icon="arrow-repeat"
                    note="Already on the list." />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat :value="$counts['duplicate']" label="Repeated in the file" icon="files"
                    :alert="$counts['duplicate'] > 0" />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat :value="$counts['invalid']" label="Cannot be read" icon="exclamation-octagon"
                    :alert="$counts['invalid'] > 0" />
        </div>
    </div>

    @if ($preview['missing'] !== [])
        {{-- Not an error. A list with no accreditation numbers still
             matches on name and barangay — but the office should know
             which columns were not found, because the usual cause is a
             heading this screen did not recognise rather than a column
             that is genuinely absent. --}}
        <div class="alert alert-warning">
            <p class="mb-1">
                <strong>Some columns were not found:</strong>
                {{ implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $preview['missing'])) }}.
            </p>
            <p class="mb-0 small">
                Those fields will be left blank. If your file does have them, check the spelling
                of the headings in the first row.
            </p>
        </div>
    @endif

    @if ($counts['invalid'] > 0 || $counts['duplicate'] > 0)
        <div class="alert alert-light border">
            <p class="mb-0 small">
                Rows that cannot be read, and rows repeated inside the file, are
                <strong>skipped</strong>. Everything else is still imported, so you can
                fix those rows and upload the file again — re-importing does not duplicate
                what has already been saved.
            </p>
        </div>
    @endif

    @if ($willWrite === 0)
        <x-empty-state icon="exclamation-triangle" title="Nothing in this file can be imported"
                       message="Every row was either unreadable or repeated. Check the column headings in the first row, then try again." />
    @endif

    <x-data-table label="Rows in the file">
        <x-slot:head>
            <tr>
                <th scope="col">Row</th>
                <th scope="col">What happens</th>
                <th scope="col">Resort</th>
                <th scope="col">Barangay</th>
                <th scope="col">Accreditation no.</th>
                <th scope="col">Status</th>
                <th scope="col">Valid until</th>
            </tr>
        </x-slot:head>

        @foreach ($preview['rows'] as $row)
            <tr>
                <td class="text-muted-vd">{{ $row['line'] }}</td>

                <td>
                    @switch($row['action'])
                        @case('create')
                            <span class="badge text-bg-success">Add</span>
                            @break
                        @case('update')
                            <span class="badge text-bg-info">Update</span>
                            @break
                        @case('duplicate')
                            <span class="badge text-bg-secondary">Skip</span>
                            @break
                        @default
                            <span class="badge text-bg-danger">Skip</span>
                    @endswitch

                    @if ($row['errors'] !== [])
                        <span class="d-block small text-muted-vd">{{ implode('; ', $row['errors']) }}</span>
                    @elseif ($row['action'] === 'update' && $row['existing'] !== null)
                        <span class="d-block small text-muted-vd">
                            Replaces "{{ $row['existing']->resort_name }}"
                        </span>
                    @endif
                </td>

                <td>
                    <span class="d-block">{{ $row['data']['resort_name'] ?: '—' }}</span>
                    @if (filled($row['data']['owner_name']))
                        <span class="small text-muted-vd">{{ $row['data']['owner_name'] }}</span>
                    @endif
                </td>

                <td>{{ $row['data']['barangay'] ?? '—' }}</td>
                <td>{{ $row['data']['accreditation_no'] ?? '—' }}</td>
                <td>{{ ucfirst($row['data']['accreditation_status']) }}</td>
                <td>{{ $row['data']['valid_until'] ?? '—' }}</td>
            </tr>
        @endforeach
    </x-data-table>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <form method="POST" action="{{ route('admin.accredited-resorts.import') }}" data-submit-once>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="extension" value="{{ $extension }}">
            <input type="hidden" name="filename" value="{{ $filename }}">

            <button type="submit" class="btn btn-primary" @disabled($willWrite === 0)>
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                Import {{ $willWrite }} {{ Str::plural('record', $willWrite) }}
            </button>
        </form>

        <a class="btn btn-light border" href="{{ route('admin.accredited-resorts.index') }}">Cancel</a>
    </div>

    <p class="text-muted-vd small mt-3 mb-0">
        After importing, resort applications still waiting for a decision are checked again
        against the updated list. Applications already approved or rejected keep the finding
        they were decided on.
    </p>
@endsection
