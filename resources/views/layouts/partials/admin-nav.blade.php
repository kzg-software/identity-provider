{{--
    Vertikale Navigation fuer die Admin-Seitenleiste.
    Erwartet $navItems und $adminItems aus layouts/admin.blade.php.
--}}
@php
    $linkClass = fn ($active) => 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition '
        .($active
            ? 'bg-laravel-50 text-laravel-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900');
@endphp

<nav class="space-y-1">
    @foreach ($navItems as $item)
        <a href="{{ route($item['route']) }}" class="{{ $linkClass(request()->routeIs($item['match'])) }}">
            <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
            <span class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach

    @if (auth()->user()?->is_admin)
        <div class="px-3 pt-5 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Administration</div>
        @foreach ($adminItems as $item)
            <a href="{{ route($item['route']) }}" class="{{ $linkClass(request()->routeIs($item['match'])) }}">
                <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    @endif
</nav>
