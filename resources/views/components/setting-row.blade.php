@props(['label', 'hint' => null])

{{--
    Eine Zeile in einer Einstellungsliste: links Bezeichnung + Kurzhilfe,
    rechts das Bedienelement. Mehrere davon in eine Karte mit
    "divide-y divide-gray-100" legen.
--}}
<div {{ $attributes->merge(['class' => 'grid gap-2 py-5 first:pt-0 last:pb-0 sm:grid-cols-3 sm:gap-6']) }}>
    <div>
        <span class="text-sm font-medium text-gray-900">{{ $label }}</span>
        @if ($hint)
            <p class="mt-1 text-xs leading-relaxed text-gray-500">{!! $hint !!}</p>
        @endif
    </div>
    <div class="sm:col-span-2">
        {{ $slot }}
    </div>
</div>
