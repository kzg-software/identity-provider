@props(['error' => null, 'name' => null])

@php
$err = $error ?? ($name ? $errors->first($name) : null);
@endphp

<select {{ $attributes->merge(['class' => 'block w-full rounded-md border px-3 py-2 shadow-sm text-sm text-gray-900 focus:border-laravel-500 focus:ring-laravel-500 '.($err ? 'border-red-300' : 'border-gray-300')]) }} @if($name) name="{{ $name }}" @endif>
    {{ $slot }}
</select>

@if ($err)
    <p class="mt-1 text-sm text-red-600">{{ $err }}</p>
@endif
