@props(['rows' => [], 'mono' => false])

<dl {{ $attributes->merge(['class' => 'divide-y divide-gray-100 text-sm']) }}>
    @foreach ($rows as $label => $value)
        <div class="grid grid-cols-1 gap-x-4 gap-y-1 py-2 sm:grid-cols-[minmax(0,11rem)_1fr]">
            <dt class="text-gray-500">{{ $label }}</dt>
            <dd class="{{ $mono ? 'font-mono text-xs' : '' }} text-gray-900 break-all">{{ ($value === null || $value === '') ? '–' : $value }}</dd>
        </div>
    @endforeach
</dl>
