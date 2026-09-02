@props([
    'icon' => 'info',
    'title' => null,
    'cell' => false,
    'colspan' => 1,
])

{{--
    Einheitlicher Leerzustand ("noch nichts da").
    - Standalone:        <x-empty-state icon="building" title="Noch keine Anwendung">Text …</x-empty-state>
    - In einer Tabelle:  <x-empty-state cell :colspan="6" title="…">Text …</x-empty-state>
    - Slot "action":     optionaler CTA-Button darunter
--}}

@php $hasAction = isset($action) && trim($action) !== ''; @endphp

@if ($cell)
<tr>
    <td colspan="{{ $colspan }}" class="px-4 py-12 text-center">
        <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
        @if ($title)<p class="mt-3 font-medium text-gray-900">{{ $title }}</p>@endif
        @if (trim($slot) !== '')<p class="mt-1 text-sm text-gray-500">{{ $slot }}</p>@endif
        @if ($hasAction)<div class="mt-4 flex justify-center">{{ $action }}</div>@endif
    </td>
</tr>
@else
<div {{ $attributes->merge(['class' => 'px-4 py-12 text-center']) }}>
    <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
        <x-icon :name="$icon" class="h-5 w-5" />
    </span>
    @if ($title)<p class="mt-3 font-medium text-gray-900">{{ $title }}</p>@endif
    @if (trim($slot) !== '')<p class="mt-1 text-sm text-gray-500">{{ $slot }}</p>@endif
    @if ($hasAction)<div class="mt-4 flex justify-center">{{ $action }}</div>@endif
</div>
@endif
