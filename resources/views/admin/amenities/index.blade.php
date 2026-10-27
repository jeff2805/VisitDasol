@extends('layouts.admin')

@section('title', 'Amenities')

@section('content')
    <x-page-header title="Amenity list"
                   subtitle="Resort owners tick these when describing their resort, and visitors filter by them." />

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel">
                <x-scroll-region label="Amenities">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Amenity</th>
                                <th scope="col">Resorts</th>
                                <th scope="col">Offered</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($amenities as $amenity)
                                <tr>
                                    <td>
                                        <form method="POST" action="{{ route('admin.amenities.update', $amenity) }}"
                                              class="d-flex flex-wrap gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <span class="vd-feature__icon" style="width:2.25rem;height:2.25rem;font-size:1rem">
                                                <i class="bi bi-{{ $amenity->icon ?? 'check-lg' }}" aria-hidden="true"></i>
                                            </span>
                                            <input class="form-control form-control-sm" style="max-width: 12rem"
                                                   name="name" value="{{ $amenity->name }}" aria-label="Amenity name" required>
                                            <input class="form-control form-control-sm" style="max-width: 9rem"
                                                   name="icon" value="{{ $amenity->icon }}" aria-label="Icon name" placeholder="icon name">
                                            <button type="submit" class="btn btn-sm btn-light border">Save</button>
                                        </form>
                                    </td>
                                    <td>{{ $amenity->resorts_count }}</td>
                                    <td>
                                        <x-status-badge :status="$amenity->is_active ? 'active' : 'inactive'" />
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            <form method="POST" action="{{ route('admin.amenities.toggle', $amenity) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-light border">
                                                    {{ $amenity->is_active ? 'Switch off' : 'Switch on' }}
                                                </button>
                                            </form>

                                            @if ($amenity->resorts_count === 0)
                                                <form method="POST" action="{{ route('admin.amenities.destroy', $amenity) }}"
                                                      data-confirm="Delete {{ $amenity->name }}?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light border text-danger">Delete</button>
                                                </form>
                                            @else
                                                <span class="btn btn-sm btn-light border disabled" aria-disabled="true"
                                                      title="Used by {{ $amenity->resorts_count }} resorts">Delete</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-scroll-region>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Add an amenity</h2>

                <form method="POST" action="{{ route('admin.amenities.store') }}" novalidate data-submit-once>
                    @csrf
                    <x-form.input name="name" label="Amenity name" required />
                    <x-form.input name="icon" label="Icon name"
                                  help="A Bootstrap Icons name such as wifi, cup-hot, or snow." />
                    <button type="submit" class="btn btn-primary">Add amenity</button>
                </form>

                <hr class="my-4">

                <h3 class="h6">Switching off, not deleting</h3>
                <p class="text-muted-vd small mb-0">
                    Switching an amenity off removes it from the owner's form but leaves it on resorts
                    that already use it. Deleting is only possible while no resort uses it.
                </p>
            </section>
        </div>
    </div>
@endsection
