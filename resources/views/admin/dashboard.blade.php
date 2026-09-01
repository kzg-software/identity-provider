@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Dashboard</h1>

{{-- Neue Version verfügbar (pro Admin/Browser ausblendbar, erscheint bei neuem Release wieder) --}}
@php $update = \App\Services\UpdateChecker::status(); @endphp
@if ($update['update_available'])
    <div class="mb-6"
         x-data="{
            key: 'dashboard_update_dismissed',
            tag: @js($update['latest']),
            dismissed: false,
            init() { try { this.dismissed = localStorage.getItem(this.key) === this.tag; } catch (e) {} },
            dismiss() { this.dismissed = true; try { localStorage.setItem(this.key, this.tag); } catch (e) {} },
         }"
         x-show="! dismissed" x-cloak>
        <div class="flex items-stretch gap-2">
            <a href="{{ route('admin.updates.index') }}"
               class="flex flex-1 items-center justify-between gap-4 rounded-lg border border-laravel-300 bg-laravel-50 px-4 py-3 text-sm transition hover:bg-laravel-100">
                <span class="flex items-center gap-2 text-laravel-700">
                    <x-icon name="sparkles" class="h-4 w-4 text-laravel-600" />
                    <span class="font-medium">Neue Version {{ $update['latest'] }} verfügbar</span>
                    <span class="text-laravel-600">— installiert: {{ $update['current'] }}</span>
                </span>
                <span class="shrink-0 text-xs text-laravel-600">Changelog &amp; Update &rarr;</span>
            </a>
            <button type="button" @click="dismiss()" title="Hinweis ausblenden"
                    class="flex shrink-0 items-center rounded-lg border border-gray-200 px-2 text-gray-400 transition hover:bg-gray-50 hover:text-gray-600">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
            </button>
        </div>
    </div>
@endif

{{-- KPIs --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
        $kpiTiles = [
            ['label' => 'Aktive Benutzer', 'value' => $kpis['users_active'], 'icon' => 'users'],
            ['label' => 'Aktive Sessions', 'value' => $kpis['sessions_active'], 'icon' => 'monitor'],
            ['label' => 'Fehlgeschlagene Logins (24h)', 'value' => $kpis['failed_logins_24h'], 'icon' => 'warning', 'accent' => $kpis['failed_logins_24h'] > 0],
            ['label' => 'Aktive Anwendungen', 'value' => $kpis['applications'], 'icon' => 'building'],
        ];
    @endphp

    @foreach ($kpiTiles as $tile)
        <x-card :padding="true">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-gray-500">{{ $tile['label'] }}</div>
                    <div class="text-3xl font-semibold {{ ($tile['accent'] ?? false) ? 'text-amber-600' : 'text-gray-900' }} mt-1">{{ $tile['value'] }}</div>
                </div>
                <span class="flex items-center justify-center h-10 w-10 rounded-full {{ ($tile['accent'] ?? false) ? 'bg-amber-100 text-amber-600' : 'bg-laravel-50 text-laravel-600' }}">
                    <x-icon :name="$tile['icon']" class="h-5 w-5" />
                </span>
            </div>
        </x-card>
    @endforeach
</div>

{{-- Warnungen (pro Admin/Browser ausblendbar; neue Warnungen erscheinen wieder) --}}
@if (count($warnings) > 0)
    <div class="mb-6"
         x-data="{
            store: 'dashboard_warnings_hidden',
            hidden: (() => { try { return JSON.parse(localStorage.getItem('dashboard_warnings_hidden') || '[]'); } catch (e) { return []; } })(),
            keys: @js(collect($warnings)->map(fn ($w) => md5($w['label']))->values()),
            persist() { try { localStorage.setItem(this.store, JSON.stringify(this.hidden)); } catch (e) {} },
            dismiss(k) { if (! this.hidden.includes(k)) this.hidden.push(k); this.persist(); },
            reset() { this.hidden = []; this.persist(); },
            get visibleCount() { return this.keys.filter(k => ! this.hidden.includes(k)).length; },
            get hiddenCount() { return this.keys.filter(k => this.hidden.includes(k)).length; },
         }">
        <div class="flex items-center justify-between gap-4 mb-3">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-700" x-show="visibleCount > 0" x-cloak>
                <x-icon name="warning" class="h-4 w-4 text-amber-500" /> Handlungsbedarf
            </h2>
            <button type="button" x-show="hiddenCount > 0" x-cloak @click="reset()"
                    class="text-xs text-gray-500 hover:text-gray-700">
                <span x-text="hiddenCount"></span> ausgeblendet — wieder einblenden
            </button>
        </div>
        <div class="space-y-2">
            @foreach ($warnings as $warning)
                @php
                    $isFail = ($warning['level'] ?? 'warn') === 'fail';
                    $wkey = md5($warning['label']);
                @endphp
                <div x-show="! hidden.includes('{{ $wkey }}')" x-cloak class="flex items-stretch gap-2">
                    <a href="{{ $warning['url'] }}" class="flex flex-1 items-center justify-between gap-4 rounded-lg border px-4 py-3 text-sm transition {{ $isFail ? 'bg-red-50 border-red-200 hover:bg-red-100' : 'bg-amber-50 border-amber-200 hover:bg-amber-100' }}">
                        <span class="flex items-center gap-2 {{ $isFail ? 'text-red-800' : 'text-amber-800' }}">
                            <x-icon name="warning" class="h-4 w-4 {{ $isFail ? 'text-red-500' : 'text-amber-500' }}" />
                            <span class="font-medium">{{ $warning['label'] }}</span>
                            <span class="{{ $isFail ? 'text-red-600' : 'text-amber-600' }}">— {{ $warning['detail'] }}</span>
                        </span>
                        <span class="text-xs {{ $isFail ? 'text-red-500' : 'text-amber-500' }} shrink-0">Ansehen &rarr;</span>
                    </a>
                    <button type="button" @click="dismiss('{{ $wkey }}')" title="Warnung ausblenden"
                            class="shrink-0 flex items-center rounded-lg border border-gray-200 px-2 text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Aktivität --}}
        <x-card :padding="false">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <x-icon name="activity" class="h-4 w-4 text-gray-400" /> Letzte Logins
                </h2>
                <a href="{{ route('admin.audit-log.index') }}" class="text-xs text-laravel-600 hover:text-laravel-700 font-medium">Alle im Audit-Log ansehen &rarr;</a>
            </div>
            <x-table>
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-500">Ereignis</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-500">Benutzer</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-500">IP</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-500">Zeitpunkt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentLogins as $log)
                        <tr>
                            <td class="px-4 py-2">
                                <x-badge :color="$log->event === 'login.success' ? 'green' : 'red'">{{ $log->event }}</x-badge>
                            </td>
                            <td class="px-4 py-2 text-gray-700">{{ $log->user?->name ?? ($log->metadata['username'] ?? '–') }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $log->ip_address }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $log->created_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-3 text-gray-400">Keine Einträge.</td></tr>
                    @endforelse
                </tbody>
            </x-table>
        </x-card>

        {{-- Sekundäre Statistik --}}
        <x-card title="Übersicht">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <div class="text-gray-500">Benutzer insgesamt</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['users_total'] }}</div>
                </div>
                <div>
                    <div class="text-gray-500">Lokal / AD</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['users_local'] }} / {{ $stats['users_ad'] }}</div>
                </div>
                <div>
                    <div class="text-gray-500">OAuth/OIDC-Clients</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['oauth_clients'] }}</div>
                </div>
                <div>
                    <div class="text-gray-500">SAML Service Provider</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['saml_providers'] }}</div>
                </div>
                <div>
                    <div class="text-gray-500">Verbundene Verzeichnisse</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['directories_connected'] }}</div>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Schnellzugriffe --}}
    <div>
        <x-card title="Schnellzugriffe" :padding="false">
            <div class="divide-y divide-gray-100">
                @php
                    $quickActions = [
                        ['route' => 'admin.applications.create', 'label' => 'Anwendung anlegen', 'icon' => 'plus'],
                        ['route' => 'admin.users.index', 'label' => 'Benutzer verwalten', 'icon' => 'users'],
                        ['route' => 'admin.directories.index', 'label' => 'Verzeichnis hinzufügen', 'icon' => 'server'],
                        ['route' => 'admin.saml-service-providers.index', 'label' => 'SAML Service Provider', 'icon' => 'shield-check'],
                        ['route' => 'admin.settings.edit', 'label' => 'Systemeinstellungen', 'icon' => 'cog'],
                        ['route' => 'admin.status.index', 'label' => 'Systemstatus prüfen', 'icon' => 'heart-pulse'],
                    ];
                @endphp
                @foreach ($quickActions as $action)
                    <a href="{{ route($action['route']) }}" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition">
                        <span class="flex items-center justify-center h-8 w-8 rounded-md bg-laravel-50 text-laravel-600">
                            <x-icon :name="$action['icon']" class="h-4 w-4" />
                        </span>
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        </x-card>
    </div>
</div>
@endsection
