@props([
    'tabs' => [],      // ['key' => 'Label', ...]
    'current' => null,  // aktiver Key
    'base' => '#',      // Basis-URL, Tab wird als ?tab=key angehängt
])

@php
    $current = $current && array_key_exists($current, $tabs) ? $current : array_key_first($tabs);
    $glue = str_contains($base, '?') ? '&' : '?';
@endphp

<div {{ $attributes->merge(['class' => 'mb-6 border-b border-gray-200']) }}>
    <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1 text-sm" aria-label="Bereiche">
        @foreach ($tabs as $key => $label)
            <a href="{{ $base.$glue.'tab='.$key }}"
               @class([
                   'whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                   'border-laravel-600 text-laravel-700' => $key === $current,
                   'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $key !== $current,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
