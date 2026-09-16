{{--
    Vertikale Navigation für die Admin-Seitenleiste.
    Erwartet $groups: Liste aus ['label' => string|null, 'items' => [Route/Match/Label/Icon]].
    Optional $dark => true für die dunkle Sidebar-Variante der Administration.
--}}
@php
    $dark = $dark ?? false;

    $linkClass = fn ($active) => 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition '
        .($dark
            ? ($active
                ? 'bg-white/10 text-white'
                : 'text-gray-300 hover:bg-white/5 hover:text-white')
            : ($active
                ? 'bg-laravel-50 text-laravel-700'
                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'));

    $labelClass = $dark
        ? 'px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 first:pt-1'
        : 'px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 first:pt-1';

    $footerClass = $dark
        ? 'mt-3 border-t border-white/10 pt-3'
        : 'mt-3 border-t border-gray-100 pt-3';

    $footerLinkClass = $dark
        ? 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-gray-300 hover:bg-white/5 hover:text-white'
        : 'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-900';
@endphp

<nav class="space-y-1">
    @foreach ($groups as $group)
        @if (! empty($group['label']))
            <div class="{{ $labelClass }}">
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

    <div class="{{ $footerClass }}">
        <a href="{{ route('dashboard') }}" class="{{ $footerLinkClass }}">
            <x-icon name="grid" class="h-4 w-4 shrink-0" />
            <span class="truncate">Zum Portal</span>
        </a>
    </div>
</nav>
