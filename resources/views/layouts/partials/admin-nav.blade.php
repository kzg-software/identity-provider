{{--
    Vertikale Navigation für die Admin-Seitenleiste.
    Erwartet $items (Liste aus Route/Match/Label/Icon).
--}}
@php
    $linkClass = fn ($active) => 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition '
        .($active
            ? 'bg-laravel-50 text-laravel-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900');
@endphp

<nav class="space-y-1">
    @foreach ($items as $item)
        <a href="{{ route($item['route']) }}" class="{{ $linkClass(request()->routeIs($item['match'])) }}">
            <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
            <span class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach

    <div class="pt-3 mt-3 border-t border-gray-100">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900">
            <x-icon name="grid" class="h-4 w-4 shrink-0" />
            <span class="truncate">Zum Portal</span>
        </a>
    </div>
</nav>
