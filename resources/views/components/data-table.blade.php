{{--
    The shell every listing table sits in.

    <x-data-table label="Resorts">
        <x-slot:head>
            <tr><th scope="col">Name</th><th scope="col">Status</th></tr>
        </x-slot:head>

        @foreach ($resorts as $resort)
            <tr>…</tr>
        @endforeach

        <x-slot:below>{{ $resorts->links() }}</x-slot:below>
    </x-data-table>

    There were eight different wrappers across the twenty-three tables in this
    project: some had row highlighting and some did not, some were compact,
    some sat in a panel and most floated on the page background. Reading two
    office screens in a row felt like reading two different systems.

    This is that shell, once.

      label    names the scrollable region for a screen reader
      compact  a denser table, for logs and calendars
      flush    the table is already inside a padded panel
--}}
@props([
    'label' => 'Table',
    'compact' => false,
    'flush' => false,
])

<div {{ $attributes->class(['vd-panel' => ! $flush, 'p-0' => ! $flush]) }}>
    {{-- Horizontal scrolling keeps a wide table usable on a phone without
         shrinking the text to nothing. <x-scroll-region> is what makes that
         scroll reachable by keyboard and announced, which it is not by
         default. --}}
    <x-scroll-region :label="$label">
        <table @class(['table', 'vd-table', 'align-middle', 'mb-0', 'table-hover', 'table-sm' => $compact])>
            @isset($head)
                <thead>{{ $head }}</thead>
            @endisset

            <tbody>{{ $slot }}</tbody>

            @isset($foot)
                <tfoot>{{ $foot }}</tfoot>
            @endisset
        </table>
    </x-scroll-region>

    @isset($below)
        <div class="vd-table__below">{{ $below }}</div>
    @endisset
</div>
