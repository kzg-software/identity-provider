@extends('layouts.admin')

@push('styles')
<style>
    .changelog-body { color: #374151; }
    .changelog-body > :first-child { margin-top: 0; }
    .changelog-body h1, .changelog-body h2, .changelog-body h3 { font-weight: 600; color: #111827; margin: 1.25rem 0 .5rem; }
    .changelog-body h1 { font-size: 1rem; }
    .changelog-body h2, .changelog-body h3 { font-size: .875rem; }
    .changelog-body p { margin: .5rem 0; }
    .changelog-body ul, .changelog-body ol { margin: .5rem 0; padding-left: 1.25rem; }
    .changelog-body ul { list-style: disc; }
    .changelog-body ol { list-style: decimal; }
    .changelog-body li { margin: .125rem 0; }
    .changelog-body a { color: #2563eb; text-decoration: underline; }
    .changelog-body code { background: #f3f4f6; border-radius: .25rem; padding: .0625rem .25rem; font-size: .8125rem; }
    .changelog-body pre { background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: .375rem; padding: .75rem; margin: .5rem 0; overflow-x: auto; }
    .changelog-body pre code { background: transparent; padding: 0; }
    .changelog-body hr { border: 0; border-top: 1px solid #e5e7eb; margin: 1rem 0; }

    html.dark .changelog-body { color: #cfd4dd; }
    html.dark .changelog-body h1, html.dark .changelog-body h2, html.dark .changelog-body h3 { color: #e7e9ee; }
    html.dark .changelog-body a { color: #93c5fd; }
    html.dark .changelog-body code { background: #2f3540; color: #e7e9ee; }
    html.dark .changelog-body pre { background: #0d0f13; border-color: #333a45; color: #e7e9ee; }
    html.dark .changelog-body hr { border-top-color: #333a45; }
</style>
@endpush

@php
    $tones = [
        'update'    => ['icon' => 'sparkles',     'bg' => 'bg-amber-100',   'fg' => 'text-amber-600',   'badge' => 'amber',  'label' => 'Update verfügbar'],
        'current'   => ['icon' => 'check-circle', 'bg' => 'bg-emerald-100', 'fg' => 'text-emerald-600', 'badge' => 'green',  'label' => 'Aktuell'],
        'dev'       => ['icon' => 'bolt',         'bg' => 'bg-blue-100',    'fg' => 'text-blue-600',    'badge' => 'blue',   'label' => 'Entwicklungsversion'],
        'error'     => ['icon' => 'warning',      'bg' => 'bg-red-100',     'fg' => 'text-red-600',     'badge' => 'red',    'label' => 'Prüfung fehlgeschlagen'],
        'unchecked' => ['icon' => 'arrow-path',   'bg' => 'bg-gray-100',    'fg' => 'text-gray-500',    'badge' => 'gray',   'label' => 'Noch nicht geprüft'],
    ];
    $t = $tones[$state];

    $headline = [
        'update'    => 'Version '.$status['latest'].' ist verfügbar',
        'current'   => 'Du hast die neueste Version',
        'dev'       => 'Du nutzt eine Entwicklungsversion',
        'error'     => 'Die Prüfung ist fehlgeschlagen',
        'unchecked' => 'Es wurde noch nicht geprüft',
    ][$state];
@endphp

@section('admin-content')
<x-page-header
    title="Aktualisierungen"
    description="Zeigt, ob eine neuere Version veröffentlicht wurde, mit Changelog und Anleitung zum Einspielen.">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.updates.check') }}">
            @csrf
            <x-button type="submit" variant="secondary" size="sm">
                <x-icon name="arrow-path" class="h-4 w-4" />Jetzt prüfen
            </x-button>
        </form>
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    {{-- Status --}}
    <x-card>
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $t['bg'] }} {{ $t['fg'] }}">
                <x-icon name="{{ $t['icon'] }}" class="h-5 w-5" />
            </span>

            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-900">{{ $headline }}</h2>
                    <x-badge :color="$t['badge']">{{ $t['label'] }}</x-badge>
                </div>

                @if ($state === 'error')
                    <p class="text-sm text-red-600">{{ $status['error'] }}</p>
                @elseif ($state === 'update')
                    <p class="text-sm text-gray-600">Unten steht, was neu ist und wie du aktualisierst.</p>
                @elseif ($state === 'current')
                    <p class="text-sm text-gray-600">Nichts zu tun. Die automatische Prüfung meldet sich, sobald es etwas Neues gibt.</p>
                @elseif ($state === 'dev')
                    <p class="text-sm text-gray-600">Ein automatischer Versionsvergleich ist damit nicht möglich. Die neueste veröffentlichte Version steht unten als Referenz.</p>
                @else
                    <p class="text-sm text-gray-600">Klick auf „Jetzt prüfen" oder warte auf die automatische Prüfung.</p>
                @endif

                {{-- Versionsvergleich --}}
                <div class="flex flex-wrap items-center gap-3">
                    <div class="rounded-lg border border-gray-200 px-3 py-1.5">
                        <div class="text-[11px] uppercase tracking-wide text-gray-400">Installiert</div>
                        <div class="font-mono text-sm font-medium text-gray-900">
                            @if ($status['current_is_release'])
                                <a href="{{ $currentReleaseUrl }}" target="_blank" rel="noopener noreferrer" class="hover:text-laravel-700">{{ $status['current'] }}</a>
                            @else
                                {{ $status['current'] }}
                            @endif
                        </div>
                    </div>

                    @if ($status['latest'])
                        <x-icon name="arrow-right" class="h-4 w-4 shrink-0 text-gray-300" />
                        <div class="rounded-lg border px-3 py-1.5 {{ $state === 'update' ? 'border-laravel-300 bg-laravel-50' : 'border-gray-200' }}">
                            <div class="text-[11px] uppercase tracking-wide {{ $state === 'update' ? 'text-laravel-700' : 'text-gray-400' }}">Neueste</div>
                            <div class="font-mono text-sm font-medium {{ $state === 'update' ? 'text-laravel-700' : 'text-gray-900' }}">
                                <a href="{{ $latestReleaseUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $status['latest'] }}</a>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                    <span>
                        @if ($status['checked_at'])
                            Zuletzt geprüft {{ \Illuminate\Support\Carbon::parse($status['checked_at'])->diffForHumans() }}
                        @else
                            Noch nicht geprüft
                        @endif
                    </span>
                    <span aria-hidden="true">&middot;</span>
                    <a href="{{ $repositoryUrl }}/releases" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 hover:text-gray-600">
                        <x-icon name="github" class="h-3.5 w-3.5" />Alle Releases
                    </a>
                </div>
            </div>
        </div>
    </x-card>

    {{-- Changelog --}}
    @if ($status['latest'])
        <x-card
            :title="$state === 'update' ? 'Was ist neu in '.$status['latest'] : 'Changelog '.$status['latest']"
            :description="! empty($status['release']['published_at']) ? 'Veröffentlicht am '.\Illuminate\Support\Carbon::parse($status['release']['published_at'])->isoFormat('LL') : null">
            @if ($changelogHtml)
                <div class="changelog-body text-sm leading-relaxed">
                    {!! $changelogHtml !!}
                </div>
            @else
                <p class="text-sm text-gray-500">
                    Für {{ $status['latest'] }} sind keine Release-Notes hinterlegt.
                    <a href="{{ $latestReleaseUrl }}" target="_blank" rel="noopener noreferrer" class="text-laravel-600 underline">Auf GitHub ansehen</a>.
                </p>
            @endif
        </x-card>
    @endif

    {{-- Anleitung --}}
    <x-card title="So aktualisierst du" description="Wähle den Weg, über den dieses System betrieben wird.">
        <div x-data="{ tab: 'docker' }" class="space-y-4">
            <div class="flex gap-1 border-b border-gray-200">
                <button type="button" @click="tab = 'docker'"
                        class="border-b-2 px-3 py-2 text-sm font-medium"
                        :class="tab === 'docker' ? 'border-laravel-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'">Docker</button>
                <button type="button" @click="tab = 'git'"
                        class="border-b-2 px-3 py-2 text-sm font-medium"
                        :class="tab === 'git' ? 'border-laravel-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-700'">Git-Deployment</button>
            </div>

            <div x-show="tab === 'docker'" class="space-y-3 text-sm text-gray-600">
                <p>Image-Tag in <code class="rounded bg-gray-100 px-1">.env</code> (<code>APP_IMAGE</code>) auf die neue Version setzen,
                    oder bei <code>:latest</code> bleiben, und dann:</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100"><code>docker compose pull
docker compose up -d</code></pre>
                <p class="text-xs text-gray-400">Migrationen laufen beim Start automatisch (<code>AUTO_MIGRATE=true</code>), sonst manuell mit
                    <code>docker compose exec app php artisan migrate --force</code>.</p>
            </div>

            <div x-show="tab === 'git'" style="display:none" class="space-y-3 text-sm text-gray-600">
                <p>Auf dem Server, Zero-Downtime-Release über <code>deploy/update.sh</code>:</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100"><code>sudo GIT_REF={{ $status['latest'] ?: 'vX.Y.Z' }} bash deploy/update.sh</code></pre>
                <p class="text-xs text-gray-400">Das Skript checkt die neue Version in eine frische Release aus, baut Caches, migriert und
                    schaltet den <code>current</code>-Symlink erst nach erfolgreichem Health-Check um.</p>
            </div>

            <p class="border-t border-gray-100 pt-3 text-xs text-gray-400">
                Vollständige Anleitung:
                <a href="{{ $repositoryUrl }}/blob/main/docs/DEPLOYMENT.md" target="_blank" rel="noopener noreferrer" class="text-laravel-600 underline">docs/DEPLOYMENT.md</a>
            </p>
        </div>
    </x-card>
</div>
@endsection
