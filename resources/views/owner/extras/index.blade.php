@extends('layouts.owner')

@section('title', 'What else you offer')

@section('content')
    <x-page-header title="What else you offer"
                   subtitle="Kayak hire, a banana boat ride, island hopping — anything besides the room.">
        <a class="btn btn-primary" href="{{ route('owner.extras.create') }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add something
        </a>
    </x-page-header>

    {{-- How these actually work, before an owner prices one. The word
         "request" is doing real work here: a guest ticking a box has
         not bought anything yet. --}}
    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <strong class="d-block">Guests ask; you answer</strong>
            These appear on your booking form as things a guest can request. Nothing is
            promised until you confirm it, because whether a boat goes out depends on the
            weather and the boatman &mdash; not on this website. They are also
            <strong>not added to the booking total</strong>: guests settle them with you
            at the resort.
        </div>
    </div>

    @if ($extras->isEmpty())
        <x-empty-state icon="water" title="Nothing listed yet"
                       message="Add a kayak, a banana boat ride, or anything else guests can ask for alongside a room." />
    @else
        <x-data-table>
            <x-slot:head>
                <tr>
                    <th scope="col">What</th>
                    <th scope="col">Price</th>
                    <th scope="col">Most at once</th>
                    <th scope="col">Asked for</th>
                    <th scope="col">On the form</th>
                    <th scope="col" class="text-end">Manage</th>
                </tr>
            </x-slot:head>

            @foreach ($extras as $extra)
                <tr>
                    <td>
                        <span class="d-block fw-semibold">{{ $extra->name }}</span>
                        @if (filled($extra->description))
                            <span class="small text-muted-vd">{{ Str::limit($extra->description, 80) }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <span class="fw-semibold">@peso($extra->price)</span>
                        <span class="d-block small text-muted-vd">{{ $extra->price_unit->suffix() }}</span>
                    </td>
                    <td>{{ $extra->max_quantity }}</td>
                    <td class="small">
                        {{ $extra->requested_count }} {{ Str::plural('request', $extra->requested_count) }}
                        <span class="d-block text-muted-vd">
                            {{ $extra->confirmed_count }} confirmed
                        </span>
                    </td>
                    <td>
                        <span class="vd-badge vd-badge--{{ $extra->active ? 'active' : 'inactive' }}">
                            {{ $extra->active ? 'Offered' : 'Hidden' }}
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary"
                           href="{{ route('owner.extras.edit', $extra) }}">Edit</a>

                        {{-- Switched off, never deleted: guests have asked for
                             this by name and their requests carry it. --}}
                        <x-confirm-form :id="'extra-status-'.$extra->id"
                                        method="PATCH"
                                        :action="route('owner.extras.status', $extra)"
                                        :title="$extra->active ? 'Stop offering this?' : 'Offer this again?'"
                                        :message="$extra->active
                                            ? 'It comes off your booking form. Requests guests have already made are unaffected, and you still have to answer them.'
                                            : 'It goes back on your booking form and guests can ask for it again.'"
                                        :trigger="$extra->active ? 'Hide' : 'Offer'"
                                        :confirm="$extra->active ? 'Yes, hide it' : 'Yes, offer it'"
                                        :variant="$extra->active ? 'danger' : 'primary'"
                                        trigger-class="btn btn-sm btn-light border" />
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    @endif
@endsection
