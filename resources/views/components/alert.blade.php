@props(['type' => 'success'])

@php
$styles = [
    'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
    'danger' => 'bg-red-50 text-red-800 border-red-200',
    'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
    'info' => 'bg-blue-50 text-blue-800 border-blue-200',
];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border px-4 py-3 text-sm mb-4 '.($styles[$type] ?? $styles['success'])]) }}>
    {{ $slot }}
</div>
