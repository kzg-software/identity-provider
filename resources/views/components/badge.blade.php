@props(['color' => 'gray'])

@php
$colors = [
    'gray' => 'bg-gray-100 text-gray-700',
    'green' => 'bg-emerald-100 text-emerald-700',
    'red' => 'bg-red-100 text-red-700',
    'amber' => 'bg-amber-100 text-amber-700',
    'blue' => 'bg-blue-100 text-blue-700',
    'laravel' => 'bg-laravel-50 text-laravel-700',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.($colors[$color] ?? $colors['gray'])]) }}>
    {{ $slot }}
</span>
