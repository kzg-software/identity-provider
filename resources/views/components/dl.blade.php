@props(['rows' => []])

<dl {{ $attributes->merge(['class' => 'divide-y divide-gray-100 text-sm']) }}>
    @foreach ($rows as $label => $value)
        <div class="flex justify-between py-2 gap-4">
            <dt class="text-gray-500">{{ $label }}</dt>
            <dd class="text-gray-900 text-right break-all">{{ $value ?? '–' }}</dd>
        </div>
    @endforeach
</dl>
