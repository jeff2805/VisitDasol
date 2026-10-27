@extends('layouts.owner')

@section('title', 'Owner dashboard')

@section('content')
    <x-page-header :title="'Welcome, '.$owner->first_name" :subtitle="$profile?->business_name" />

    @if ($profile?->reviewed_at)
        <div class="alert alert-success d-flex gap-2" role="status">
            <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
            <div>Your owner account was approved on {{ $profile->reviewed_at->format('M j, Y') }}.</div>
        </div>
        
<section class="vd-greeting">
    <img
    class="vd-greeting__photo"
    src="{{ asset('images/image1.png') }}"
    alt="Tropical beach in Dasol, Pangasinan"
    width="1600"
    height="900"
    />
</section>

    @endif

    @if ($resort === null)
        <x-empty-state icon="house-add" title="Your resort is not registered yet"
                       message="Submit your resort details for review. Once the Municipal Tourism Office approves it, it appears publicly and guests can book your rooms.">
            <a class="btn btn-primary" href="{{ route('owner.resort.create') }}">Register my resort</a>
        </x-empty-state>
    @else
        {{-- Three figures, one row, all through <x-stat>.

             The page used to show one of them through the component and build
             another by hand in a panel of its own, so two tiles meaning the
             same kind of thing looked like different furniture. --}}
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-4">
                <x-stat :value="$resort->active_rooms_count" label="Bookable room types" icon="door-open"
                        :note="$resort->rooms_count.' '.Str::plural('room type', $resort->rooms_count).' in total'" />
            </div>
            <div class="col-sm-6 col-xl-4">
                <x-stat :value="$waitingCount" label="Requests waiting for you" icon="hourglass-split"
                        note="A request holds the rooms until you answer." />
            </div>
            <div class="col-xl-4">
                {{-- Money at the owner's own listed rates, over confirmed
                     stays only. The note says so, because this site takes no
                     payment and the figure is not a record of what was
                     collected. --}}
                <x-stat :value="\App\Support\Money::peso($comingValue)" label="Upcoming confirmed stays"
                        icon="cash-coin"
                        :note="$comingCount.' '.Str::plural('stay', $comingCount).' at your listed rates'" />
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <section class="vd-panel p-4 h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h4 mb-1">{{ $resort->name }}</h2>
                            <p class="text-muted-vd mb-0">{{ $resort->full_address }}</p>
                        </div>
                        <x-status-badge :status="$resort->status" />
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-sm btn-primary" href="{{ route('owner.resort.show') }}">Open my resort</a>
                        <a class="btn btn-sm btn-light border" href="{{ route('owner.resort.edit') }}">Edit details</a>
                    </div>

                    @if ($resort->status === \App\Enums\ResortStatus::Pending)
                        <div class="alert alert-warning mt-3 mb-0 d-flex gap-2" role="status">
                            <i class="bi bi-hourglass-split mt-1" aria-hidden="true"></i>
                            <div>Waiting for review. Guests cannot see or book this resort yet.</div>
                        </div>
                    @elseif ($resort->status === \App\Enums\ResortStatus::Rejected)
                        <div class="alert alert-danger mt-3 mb-0 d-flex gap-2" role="alert">
                            <i class="bi bi-x-octagon-fill mt-1" aria-hidden="true"></i>
                            <div>
                                <strong>This resort was not approved.</strong>
                                @if (filled($resort->review_remarks)) {{ $resort->review_remarks }} @endif
                            </div>
                        </div>
                    @elseif ($resort->status === \App\Enums\ResortStatus::Inactive)
                        <div class="alert alert-warning mt-3 mb-0 d-flex gap-2" role="status">
                            <i class="bi bi-eye-slash mt-1" aria-hidden="true"></i>
                            <div>This listing is hidden from the public. Contact the tourism office for details.</div>
                        </div>
                    @endif
                </section>
            </div>

            {{-- The two things an owner acts on, beside the listing itself.
                 These used to be a second row below, behind a repeat of the
                 same "has this owner got a resort" question that opened this
                 branch. --}}
            <div class="col-lg-4">
                <section class="vd-panel p-4 h-100" aria-labelledby="next-stays-title">
                    <h2 class="h5 mb-3" id="next-stays-title">Next confirmed stays</h2>

                    @if ($upcoming->isEmpty())
                        <p class="text-muted-vd mb-0">No confirmed stays coming up.</p>
                    @else
                        <ul class="vd-upnext">
                            @foreach ($upcoming as $booking)
                                <li>
                                    <span>
                                        <a href="{{ route('owner.bookings.show', $booking) }}">{{ $booking->contact_name }}</a>
                                        <span class="d-block small text-muted-vd">{{ $booking->room?->name }}</span>
                                    </span>
                                    <span class="text-end small">
                                        {{ $booking->check_in_date->format('M j') }} to {{ $booking->check_out_date->format('M j') }}
                                        <span class="d-block text-muted-vd">{{ $booking->guests }} {{ Str::plural('guest', $booking->guests) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        <a class="btn btn-sm btn-light border mt-3" href="{{ route('owner.bookings.index') }}">
                            All reservations
                        </a>
                    @endif
                </section>
            </div>
        </div>

        @if ($waitingCount > 0)
            {{-- Only when there is something to answer. A panel that says
                 "0 requests waiting" every day is furniture; one that appears
                 when somebody is actually waiting is a message. --}}
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-3 mt-4" role="status">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <strong>{{ $waitingCount }} {{ Str::plural('request', $waitingCount) }}</strong>
                    {{ $waitingCount === 1 ? 'is' : 'are' }} waiting for your answer.
                    Each one holds its rooms until you reply, so answering frees dates you cannot take.
                </div>
                <a class="btn btn-sm btn-primary" href="{{ route('owner.bookings.index', ['status' => 'pending']) }}">
                    Answer {{ $waitingCount === 1 ? 'it' : 'them' }}
                </a>
            </div>
        @endif

        {{-- Inside the branch, because every line of it needs a resort: an
             owner who has not registered one was being told to answer requests
             and sent to an availability calendar with nothing in it. --}}
        <section class="vd-panel p-4 mt-4" aria-labelledby="tips-title">
            <h2 class="h5" id="tips-title">Keeping your listing working for you</h2>
            <ul class="text-muted-vd mb-0 ps-3">
                <li>Answer requests quickly: they hold your rooms until you reply.</li>
                <li>Keep photos and rates current, and mark stays completed after guests check out.</li>
                <li>Use <a href="{{ route('owner.availability') }}">Availability</a> to see which nights are still free.</li>
            </ul>
        </section>
    @endif
@endsection
