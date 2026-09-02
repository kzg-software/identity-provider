@props(['heads' => null])

{{--
    $heads: optionales Array von Spaltentiteln. Ist es gesetzt, rendert die Komponente
    den <thead> selbst und der Slot liefert nur noch <tbody>. Ein leerer String als
    Titel erzeugt eine Aktionsspalte ohne Beschriftung.
--}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto bg-white shadow-sm sm:rounded-lg border border-gray-200']) }}>
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        @if (is_array($heads))
            <thead class="bg-gray-50">
                <tr>
                    @foreach ($heads as $head)
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 {{ $head === '' ? 'w-0' : '' }}">{{ $head }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        {{ $slot }}
    </table>
</div>
