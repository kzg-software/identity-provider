@extends('layouts.app')

@section('content')
@php
    $adminNav = [
        ['label' => null, 'items' => [
            ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'label' => 'Übersicht', 'icon' => 'grid'],
        ]],
        ['label' => 'Benutzer & Zugriff', 'items' => [
            ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'label' => 'Benutzer', 'icon' => 'users'],
            ['route' => 'admin.directories.index', 'match' => 'admin.directories.*', 'label' => 'Verzeichnisse', 'icon' => 'server'],
            ['route' => 'admin.group-role-mappings.index', 'match' => 'admin.group-role-mappings.*', 'label' => 'Rollen-Mapping', 'icon' => 'signpost'],
        ]],
        ['label' => 'Anwendungen', 'items' => [
            ['route' => 'admin.applications.index', 'match' => 'admin.applications.*', 'label' => 'Anwendungen', 'icon' => 'building'],
            ['route' => 'admin.providers.index', 'match' => 'admin.providers.*', 'label' => 'Provider', 'icon' => 'key'],
        ]],
        ['label' => 'Schlüssel & Zertifikate', 'items' => [
            ['route' => 'admin.oidc-keys.index', 'match' => 'admin.oidc-keys.*', 'label' => 'OIDC-Schlüssel', 'icon' => 'key'],
            ['route' => 'admin.saml-certificates.index', 'match' => 'admin.saml-certificates.*', 'label' => 'SAML-Zertifikate', 'icon' => 'lock-closed'],
        ]],
        ['label' => 'System', 'items' => [
            ['route' => 'admin.settings.edit', 'match' => 'admin.settings.*', 'label' => 'Systemeinstellungen', 'icon' => 'cog'],
            ['route' => 'admin.backups.index', 'match' => 'admin.backups.*', 'label' => 'Datensicherung', 'icon' => 'download'],
            ['route' => 'admin.status.index', 'match' => 'admin.status.*', 'label' => 'Systemstatus', 'icon' => 'heart-pulse'],
            ['route' => 'admin.mail-queue.index', 'match' => 'admin.mail-queue.*', 'label' => 'E-Mail-Warteschlange', 'icon' => 'mail'],
            ['route' => 'admin.updates.index', 'match' => 'admin.updates.*', 'label' => 'Aktualisierungen', 'icon' => 'sparkles'],
            ['route' => 'admin.audit-log.index', 'match' => 'admin.audit-log.*', 'label' => 'Audit-Log', 'icon' => 'journal'],
            ['route' => 'admin.sessions.index', 'match' => 'admin.sessions.*', 'label' => 'Alle Sitzungen', 'icon' => 'monitor'],
        ]],
    ];

    // Titel der aktuellen Seite für die Kopfleiste, aus der aktiven Nav-Gruppe ermittelt.
    $currentPageLabel = 'Administration';
    foreach ($adminNav as $group) {
        foreach ($group['items'] as $item) {
            if (request()->routeIs($item['match'])) {
                $currentPageLabel = $item['label'];
                break 2;
            }
        }
    }
@endphp
<div x-data="{ mobileOpen: false }" class="min-h-screen bg-gray-100 flex">
    {{-- Mobile Sidebar Overlay --}}
    <div x-show="mobileOpen" x-transition.opacity style="display:none" class="fixed inset-0 z-40 bg-black/40 lg:hidden" @click="mobileOpen = false"></div>

    {{-- Sidebar (dunkel, eigenständige Administrations-Optik). Auf Desktop immer sichtbar
         (lg:translate-x-0), auf Mobile ein- und ausfahrbares Off-Canvas-Menü. --}}
    <aside
        :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed inset-y-0 left-0 z-50 w-64 shrink-0 flex flex-col bg-gray-900 transition-transform duration-200 ease-out lg:translate-x-0 lg:static"
    >
        <div class="flex items-center justify-between h-16 px-4 border-b border-white/10 shrink-0">
            <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-2 font-semibold text-white">
                <x-brand-mark context="header" />
                @if (! empty($headerTitle))
                    <span class="truncate">{{ $headerTitle }}</span>
                @endif
            </a>
            <button @click="mobileOpen = false" class="lg:hidden p-1 text-gray-400 hover:text-white" aria-label="Menü schließen">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="px-3 pt-3">
            <span class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-medium text-gray-200">Administration</span>
        </div>

        <div class="flex-1 overflow-y-auto px-3 py-3" @click="mobileOpen = false">
            @include('layouts.partials.admin-nav', ['groups' => $adminNav, 'dark' => true])
        </div>
    </aside>

    {{-- Content-Bereich --}}
    <div class="flex-1 min-w-0 flex flex-col">
        {{-- Eigene Admin-Kopfleiste, bewusst anders als das Portal --}}
        <header class="sticky top-0 z-30 bg-white border-b border-gray-200">
            <div class="flex items-center justify-between h-16 gap-4 px-4 sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button @click="mobileOpen = !mobileOpen" class="lg:hidden -ml-2 p-2 text-gray-500 hover:text-gray-700" aria-label="Menü">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                    <h1 class="truncate text-base font-semibold text-gray-900">{{ $currentPageLabel }}</h1>
                </div>

                <div class="flex items-center gap-3">
                    <a href="{{ route('dashboard') }}"
                       class="hidden sm:inline-flex shrink-0 whitespace-nowrap items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <x-icon name="grid" class="h-4 w-4 shrink-0" />
                        Zum Portal
                    </a>

                    <x-notification-bell />

                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button @click="open = !open" class="flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900">
                            <span class="flex items-center justify-center h-8 w-8 rounded-full bg-gray-200 text-gray-600 text-xs font-semibold">
                                {{ strtoupper(substr(auth()->user()->display_name ?? auth()->user()->username ?? '?', 0, 1)) }}
                            </span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </button>
                        <div x-show="open" x-transition style="display:none" class="absolute right-0 mt-2 w-64 rounded-md shadow-lg bg-white border border-gray-200 py-1 z-50">
                            <div class="px-4 py-2 text-xs text-gray-400 border-b border-gray-100">
                                Angemeldet als <span class="font-medium text-gray-600">{{ auth()->user()->display_name ?? auth()->user()->username }}</span>
                            </div>
                            <a href="{{ route('profile.index') }}" class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-100 {{ request()->routeIs('profile.*') ? 'text-laravel-700 font-medium' : 'text-gray-700' }}">
                                <x-icon name="user" class="h-4 w-4 text-gray-400" />Mein Account
                            </a>
                            <a href="{{ route('dashboard') }}" class="sm:hidden flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <x-icon name="grid" class="h-4 w-4 text-gray-400" />Zum Portal
                            </a>
                            <div class="border-t border-gray-100"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><x-icon name="logout" class="h-4 w-4 text-gray-400" />Abmelden</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        @if (session()->has('impersonate.admin_id'))
            <div class="bg-amber-500 text-white">
                <div class="px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between gap-4 text-sm">
                    <span class="flex items-center gap-2"><x-icon name="login" class="h-4 w-4" /> Du bist gerade als <strong>{{ auth()->user()->display_name ?? auth()->user()->name }}</strong> angemeldet.</span>
                    <form method="POST" action="{{ route('impersonate.stop') }}">
                        @csrf
                        <button type="submit" class="underline font-medium hover:text-amber-100">Zurück zum Admin-Konto</button>
                    </form>
                </div>
            </div>
        @endif

        <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6">
            <x-flash />

            @yield('admin-content')
        </main>

        <x-app-footer />
    </div>
</div>
@endsection
