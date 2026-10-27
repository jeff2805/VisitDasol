{{--
    Year picker for a dashboard card. Both cards read the same ?year=, so
    changing one moves the other with it.

    @include('admin.partials.year-filter', ['id' => 'arrivalsYear', 'label' => '...'])

    The select applies itself through data-auto-submit; the submit button is
    hidden but real, so the keyboard alone and a browser without JavaScript
    both still work.
--}}
<form class="vd-yearpick" method="GET" action="{{ route('admin.dashboard') }}">
    <label class="visually-hidden" for="{{ $id }}">{{ $label }}</label>
    <i class="bi bi-calendar3" aria-hidden="true"></i>
    <select class="vd-yearpick__select" id="{{ $id }}" name="year" data-auto-submit>
        @foreach ($years as $option)
            <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
        @endforeach
    </select>
    <button class="visually-hidden" type="submit">Show this year</button>
</form>
