@extends('layouts.app')

@section('content')
@php
    $isAdmin = (bool) (auth()->user()?->is_admin);

    $portalNav = collect([
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Meine Anwendungen', 'icon' => 'grid'],
    ]);

    // Persönlicher Bereich im Profilmenü (dort, wo auch "Abmelden" liegt).
    // "Mein Account" ist die Übersicht mit allen weiteren Unterseiten.
    $profileNav = [
        ['route' => 'profile.index', 'match' => 'profile.*', 'label' => 'Mein Account', 'icon' => 'user'],
    ];
@endphp
<div x-data="{ mobileOpen: false }" class="min-h-screen bg-gray-100 flex flex-col">
    {{-- Header --}}
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 gap-4">
                <div class="flex min-w-0 items-center self-stretch">
                    <button @click="mobileOpen = !mobileOpen" class="lg:hidden self-center -ml-2 p-2 text-gray-500 hover:text-gray-700" aria-label="Menü">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0 font-semibold text-gray-800">
                        <x-brand-mark context="header" />
                        @if (empty($systemLogoUrl) && ! empty($headerTitle))
                            <span>{{ $headerTitle }}</span>
                        @endif
                    </a>

                    <div class="hidden lg:flex lg:ml-8 lg:space-x-6 self-stretch overflow-x-auto">
                        @foreach ($portalNav as $item)
                            <a href="{{ route($item['route']) }}" class="inline-flex items-center gap-1.5 px-1 border-b-2 text-sm font-medium whitespace-nowrap {{ request()->routeIs($item['match']) ? 'border-laravel-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                                <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    @if ($isAdmin)
                        <a href="{{ route('admin.dashboard') }}"
                           class="hidden sm:inline-flex shrink-0 whitespace-nowrap items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            <x-icon name="cog" class="h-4 w-4 shrink-0" />
                            Administration
                        </a>
                    @endif

                    <x-notification-bell />

                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button @click="open = !open" class="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                            <span class="flex items-center justify-center h-8 w-8 rounded-full bg-gray-200 text-gray-600 text-xs font-semibold">
                                {{ strtoupper(substr(auth()->user()->display_name ?? auth()->user()->username ?? '?', 0, 1)) }}
                            </span>
                            <span class="hidden sm:inline">{{ auth()->user()->display_name ?? auth()->user()->username }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </button>
                        <div x-show="open" x-transition style="display:none" class="absolute right-0 mt-2 w-64 rounded-md shadow-lg bg-white border border-gray-200 py-1 z-50">
                            <div class="px-4 py-2 text-xs text-gray-400 border-b border-gray-100">
                                Angemeldet als <span class="font-medium text-gray-600">{{ auth()->user()->display_name ?? auth()->user()->username }}</span>
                            </div>
                            @foreach ($profileNav as $item)
                                <a href="{{ route($item['route']) }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-100 {{ request()->routeIs($item['match']) ? 'text-laravel-700 font-medium' : 'text-gray-700' }}">
                                    <x-icon :name="$item['icon']" class="h-4 w-4 text-gray-400" />{{ $item['label'] }}
                                </a>
                            @endforeach
                            <div class="border-t border-gray-100"></div>
                            @if ($isAdmin)
                                <a href="{{ route('admin.dashboard') }}" class="sm:hidden flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <x-icon name="cog" class="h-4 w-4 text-gray-400" />Administration
                                </a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><x-icon name="logout" class="h-4 w-4 text-gray-400" />Abmelden</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="mobileOpen" x-transition style="display:none" class="lg:hidden border-t border-gray-200 bg-white">
            @foreach ($portalNav as $item)
                <a href="{{ route($item['route']) }}" class="flex items-center gap-2 px-4 py-3 text-sm {{ request()->routeIs($item['match']) ? 'bg-laravel-50 text-laravel-700 font-medium' : 'text-gray-600' }}"><x-icon :name="$item['icon']" class="h-4 w-4" />{{ $item['label'] }}</a>
            @endforeach
        </div>
    </header>

    @if (session()->has('impersonate.admin_id'))
        <div class="bg-amber-500 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between gap-4 text-sm">
                <span class="flex items-center gap-2"><x-icon name="login" class="h-4 w-4" /> Du bist gerade als <strong>{{ auth()->user()->display_name ?? auth()->user()->name }}</strong> angemeldet.</span>
                <form method="POST" action="{{ route('impersonate.stop') }}">
                    @csrf
                    <button type="submit" class="underline font-medium hover:text-amber-100">Zurück zum Admin-Konto</button>
                </form>
            </div>
        </div>
    @endif

    <div class="flex-1">
        <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <x-flash />

            @yield('admin-content')
        </main>
    </div>

    <x-app-footer />
</div>
@endsection
