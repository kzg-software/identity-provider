@props(['variant' => 'primary', 'size' => 'md', 'tag' => 'button'])

@php
$base = 'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition ease-in-out duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

$sizes = [
    'sm' => 'px-3 py-1.5 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-5 py-2.5 text-base',
];

$variants = [
    'primary' => 'bg-laravel-600 border border-transparent text-white hover:bg-laravel-700 focus:ring-laravel-500',
    'secondary' => 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 focus:ring-gray-400',
    'danger' => 'bg-red-600 border border-transparent text-white hover:bg-red-700 focus:ring-red-500',
    'success' => 'bg-emerald-600 border border-transparent text-white hover:bg-emerald-700 focus:ring-emerald-500',
    'link' => 'text-laravel-600 hover:text-laravel-700 font-medium px-0 py-0',
];

$classes = $base.' '.($size !== null && $variant !== 'link' ? ($sizes[$size] ?? $sizes['md']) : '').' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($tag === 'a')
    <a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>{{ $slot }}</button>
@endif
