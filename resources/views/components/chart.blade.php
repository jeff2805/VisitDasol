{{--
    A chart drawn from numbers already on the page.

    <x-chart type="line" :labels="$labels" :series="[['label' => 'Reservations', 'data' => $data]]"
             height="18rem" prefix="₱" />

    Presentation options, all optional, all off by default so existing charts
    keep the look they had:

      legend   false to hide the built-in key (the page draws its own)
      points   radius of the dots on a line, 0 for a clean curve
      fill     "gradient" to fade a line's fill out towards the axis
      cutout   hole size of a doughnut, e.g. "72%"
--}}
@props([
    'type' => 'line',
    'labels' => [],
    'series' => [],
    'height' => '16rem',
    'prefix' => null,
    'suffix' => null,
    'title' => null,
    'legend' => null,
    'points' => null,
    'fill' => null,
    'cutout' => null,
])

@once
    @push('scripts')
        <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
        <script src="@vdasset('js/charts.js')"></script>
    @endpush
@endonce

<div {{ $attributes->class('vd-chart') }} style="height: {{ $height }}">
    <canvas data-chart="{{ $type }}"
            data-labels='@json($labels)'
            data-series='@json($series)'
            @if ($prefix) data-prefix="{{ $prefix }}" @endif
            @if ($suffix) data-suffix="{{ $suffix }}" @endif
            @if ($legend !== null) data-legend="{{ $legend ? 'true' : 'false' }}" @endif
            @if ($points !== null) data-points="{{ $points }}" @endif
            @if ($fill) data-fill="{{ $fill }}" @endif
            @if ($cutout) data-cutout="{{ $cutout }}" @endif
            @if ($title) aria-label="{{ $title }}" role="img" @endif></canvas>
</div>
