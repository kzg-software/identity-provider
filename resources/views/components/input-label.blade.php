@props(['value' => null])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-gray-700 mb-1']) }}>
    {{ $value ?? $slot }}
</label>
