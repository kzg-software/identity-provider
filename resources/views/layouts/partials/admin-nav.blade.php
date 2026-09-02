{{--
    Vertikale Navigation für die Admin-Seitenleiste.
    Erwartet $groups: Liste aus ['label' => string|null, 'items' => [Route/Match/Label/Icon]].
--}}
@php
    $linkClass = fn ($active) => 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition '
        .($active
            ? 'bg-laravel-50 text-laravel-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900');
@endphp

<nav class="space-y-1">
    @foreach ($groups as $group)
        @if (! empty($group['label']))
            <div class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 first:pt-1">
                {{ $group['label'] }}
            </div>
        @endif
        @foreach ($group['items'] as $item)
            <a href="{{ route($item['route']) }}" class="{{ $linkClass(request()->routeIs($item['match'])) }}">
                <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    @endforeach

    <div class="mt-3 border-t border-gray-100 pt-3">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900">
            <x-icon name="grid" class="h-4 w-4 shrink-0" />
            <span class="truncate">Zum Portal</span>
        </a>
    </div>
</nav>
