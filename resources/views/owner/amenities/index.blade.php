@extends('layouts.owner')

@section('title', 'Amenities')

@section('content')
    <x-page-header title="Amenities"
                   subtitle="What every resort on Visit Dasol can offer. Tick the ones yours has on your resort page; add one here if it is missing.">
        <a class="btn btn-light border" href="{{ route('owner.resort.edit') }}">Back to my resort</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel">
                <x-scroll-region label="Amenities">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Amenity</th>
                                <th scope="col">Resorts offering it</th>
                                <th scope="col">On my resort</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($amenities as $amenity)
                                <tr>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <span class="vd-feature__icon" style="width:2.25rem;height:2.25rem;font-size:1rem">
                                                <i class="bi bi-{{ $amenity->icon ?? 'check-lg' }}" aria-hidden="true"></i>
                                            </span>
                                            {{ $amenity->name }}
                                        </span>
                                    </td>

                                    {{-- Across the whole municipality, not just this
                                         owner's. It is what tells somebody whether
                                         the thing they are about to add already has
                                         a name everybody else is using. --}}
                                    <td>{{ $amenity->resorts_count }}</td>

                                    <td>
                                        @if (in_array($amenity->id, $mine, true))
                                            <span class="vd-badge vd-badge--success">Yes</span>
                                        @else
                                            <span class="text-muted-vd">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted-vd">
                                        Nothing on the list yet. Add the first one.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-scroll-region>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Add an amenity</h2>

                <form method="POST" action="{{ route('owner.amenities.store') }}">
                    @csrf

                    <x-form.input name="name" label="Amenity name" required
                                  :value="old('name')"
                                  help="What guests would call it, such as “Kayak hire” or “Videoke”." />

                    <x-form.input name="icon" label="Icon name" :value="old('icon')"
                                  help="Optional. A Bootstrap Icons name such as wifi, cup-hot, or water." />

                    <button type="submit" class="btn btn-primary">Add amenity</button>
                </form>
            </section>

            {{--
                Said plainly, because the consequence is not obvious from
                the form. One table serves every resort and the public
                filter, so a careless entry is everybody's problem.
            --}}
            <aside class="vd-panel p-4">
                <h2 class="h6 mb-2">This list is shared</h2>
                <p class="small text-muted-vd mb-2">
                    Every resort on Visit Dasol picks from the same list, and visitors
                    filter the search by it. Adding something puts it in front of all of
                    them, so check it is not already here under another name first —
                    “Wi-Fi” and “WiFi” would split the filter in two and each would find
                    only half the resorts.
                </p>
                <p class="small text-muted-vd mb-0">
                    Nothing here can be renamed or removed from this page, because other
                    resorts may already be using it. Ask the tourism office if something
                    is wrong with an entry.
                </p>
            </aside>
        </div>
    </div>
@endsection
