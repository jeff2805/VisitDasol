@extends('layouts.admin')

@section('title', 'Destination categories')

@section('content')
    <x-page-header title="Destination categories"
                   subtitle="Visitors filter destinations by these. A category in use cannot be deleted." />

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel">
                <x-scroll-region label="Destination categories">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Category</th>
                                <th scope="col">Destinations</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr>
                                    <td>
                                        <form method="POST" action="{{ route('admin.destination-categories.update', $category) }}"
                                              class="d-flex flex-wrap gap-2 align-items-center">
                                            @csrf
                                            @method('PUT')
                                            <span class="vd-feature__icon" style="width:2.25rem;height:2.25rem;font-size:1rem">
                                                <i class="bi bi-{{ $category->icon ?? 'geo-alt' }}" aria-hidden="true"></i>
                                            </span>
                                            <input class="form-control form-control-sm" style="max-width: 12rem"
                                                   name="name" value="{{ $category->name }}" aria-label="Category name" required>
                                            <input class="form-control form-control-sm" style="max-width: 9rem"
                                                   name="icon" value="{{ $category->icon }}" aria-label="Icon name" placeholder="icon name">
                                            <button type="submit" class="btn btn-sm btn-light border">Save</button>
                                        </form>
                                    </td>
                                    <td>{{ $category->destinations_count }}</td>
                                    <td class="text-end">
                                        @if ($category->destinations_count === 0)
                                            <form method="POST" action="{{ route('admin.destination-categories.destroy', $category) }}"
                                                  data-confirm="Delete {{ $category->name }}?">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-light border text-danger">Delete</button>
                                            </form>
                                        @else
                                            <span class="btn btn-sm btn-light border disabled" aria-disabled="true"
                                                  title="In use by {{ $category->destinations_count }} destinations">Delete</span>
                                        @endif
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
                <h2 class="h5 mb-3">Add a category</h2>

                <form method="POST" action="{{ route('admin.destination-categories.store') }}" novalidate data-submit-once>
                    @csrf
                    <x-form.input name="name" label="Category name" required />
                    <x-form.input name="icon" label="Icon name"
                                  help="A Bootstrap Icons name such as tree, umbrella, or binoculars." />
                    <button type="submit" class="btn btn-primary">Add category</button>
                </form>
            </section>
        </div>
    </div>
@endsection
