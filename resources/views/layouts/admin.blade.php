@extends('layouts.app')

@section('content')
@php
$isAdmin = (bool) (auth()->user()?->is_admin);

$navItems = collect();
$navItems->push(['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid']);
$navItems->push(['route' => 'profile.sessions', 'match' => 'profile.sessions*', 'label' => 'Meine Sitzungen', 'icon' => 'monitor']);
$adminItems = collect([
    ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'label' => 'Benutzer', 'icon' => 'users'],
    ['route' => 'admin.directories.index', 'match' => 'admin.directories.*', 'label' => 'Verzeichnisse', 'icon' => 'server'],
    ['route' => 'admin.group-role-mappings.index', 'match' => 'admin.group-role-mappings.*', 'label' => 'Rollen-Mapping', 'icon' => 'signpost'],
    ['route' => 'admin.applications.index', 'match' => 'admin.applications.*', 'label' => 'Anwendungen', 'icon' => 'building'],
    ['route' => 'admin.oidc-keys.index', 'match' => 'admin.oidc-keys.*', 'label' => 'OIDC-Schlüssel', 'icon' => 'key'],
    ['route' => 'admin.saml-service-providers.index', 'match' => 'admin.saml-service-providers.*', 'label' => 'SAML Service Provider', 'icon' => 'shield-check'],
    ['route' => 'admin.saml-certificates.index', 'match' => 'admin.saml-certificates.*', 'label' => 'SAML-Zertifikate', 'icon' => 'lock-closed'],
    ['route' => 'admin.settings.edit', 'match' => 'admin.settings.*', 'label' => 'Systemeinstellungen', 'icon' => 'cog'],
    ['route' => 'admin.status.index', 'match' => 'admin.status.*', 'label' => 'Systemstatus', 'icon' => 'heart-pulse'],
    ['route' => 'admin.updates.index', 'match' => 'admin.updates.*', 'label' => 'Aktualisierungen', 'icon' => 'sparkles'],
    ['route' => 'admin.audit-log.index', 'match' => 'admin.audit-log.*', 'label' => 'Audit-Log', 'icon' => 'journal'],
    ['route' => 'admin.sessions.index', 'match' => 'admin.sessions.*', 'label' => 'Alle Sessions', 'icon' => 'monitor'],
]);
@endphp
<div x-data="{ mobileOpen: false }" class="min-h-screen bg-gray-100 flex flex-col">
    {{-- Header --}}
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 gap-4">
                <div class="flex min-w-0">
                    <button @click="mobileOpen = !mobileOpen" class="lg:hidden self-center -ml-2 p-2 text-gray-500 hover:text-gray-700" aria-label="Menü">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0 font-semibold text-gray-800">
                        @if (! empty($systemLogoUrl))
                            <img src="{{ $systemLogoUrl }}" alt="{{ $systemName }}" class="h-8 max-w-[10rem] object-contain">
                        @else
                            <span class="flex items-center justify-center h-8 w-8 rounded-md bg-laravel-600 text-white">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                            </span>
                            {{ $systemName }}
                        @endif
                    </a>

                    @unless ($isAdmin)
                        {{-- Normale Nutzer: gewohnte horizontale Navigation im Header --}}
                        <div class="hidden lg:flex lg:ml-8 lg:space-x-6 overflow-x-auto">
                            @foreach ($navItems as $item)
                                <a href="{{ route($item['route']) }}" class="inline-flex items-center gap-1.5 px-1 pt-1 border-b-2 text-sm font-medium whitespace-nowrap {{ request()->routeIs($item['match']) ? 'border-laravel-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                                    <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0" />
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endunless
                </div>

                <div class="flex items-center gap-3">
                    <div class="hidden sm:block">
                        <x-theme-toggle />
                    </div>

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
                            <div class="sm:hidden border-b border-gray-100 px-4 py-2.5">
                                <div class="text-xs uppercase tracking-wide text-gray-400 mb-1.5">Farbschema</div>
                                <x-theme-toggle />
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><x-icon name="logout" class="h-4 w-4 text-gray-400" />Abmelden</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @unless ($isAdmin)
            {{-- Normale Nutzer: mobiles Menü direkt unter dem Header --}}
            <div x-show="mobileOpen" x-transition style="display:none" class="lg:hidden border-t border-gray-200 bg-white">
                @foreach ($navItems as $item)
                    <a href="{{ route($item['route']) }}" class="flex items-center gap-2 px-4 py-3 text-sm {{ request()->routeIs($item['match']) ? 'bg-laravel-50 text-laravel-700 font-medium' : 'text-gray-600' }}"><x-icon :name="$item['icon']" class="h-4 w-4" />{{ $item['label'] }}</a>
                @endforeach
            </div>
        @endunless
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
    @if ($isAdmin)
        {{-- Administration: Seitenleiste + Hauptbereich, gemeinsam im Container --}}
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div x-show="mobileOpen" x-transition style="display:none" class="lg:hidden mb-4">
                <div class="rounded-lg border border-gray-200 bg-white p-2" @click="mobileOpen = false">
                    @include('layouts.partials.admin-nav')
                </div>
            </div>

            <div class="flex gap-6 lg:gap-8 items-start">
                <aside class="hidden lg:block w-56 shrink-0">
                    <div class="sticky top-6 max-h-[calc(100vh-3rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-2">
                        @include('layouts.partials.admin-nav')
                    </div>
                </aside>

                <main class="flex-1 min-w-0">
                    <x-flash />

                    @yield('admin-content')
                </main>
            </div>
        </div>
    @else
        <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <x-flash />

            @yield('admin-content')
        </main>
    @endif
    </div>

    <x-app-footer />
</div>
@endsection
