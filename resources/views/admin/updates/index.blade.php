@extends('layouts.admin')

@push('styles')
<style>
    .changelog-body { color: #374151; }
    .changelog-body h1, .changelog-body h2, .changelog-body h3 { font-weight: 600; color: #111827; margin: 1rem 0 .5rem; }
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

@section('admin-content')

<div class="flex items-center justify-between gap-4 flex-wrap mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Aktualisierungen</h1>
    <form method="POST" action="{{ route('admin.updates.check') }}">
        @csrf
        <x-button type="submit" variant="secondary">
            <x-icon name="arrow-path" class="h-4 w-4" /> Jetzt prüfen
        </x-button>
    </form>
</div>

<x-card class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="space-y-1.5">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">Status</span>
                <x-badge :color="$badgeColor">{{ $badgeText }}</x-badge>
            </div>

            <div class="text-sm text-gray-600">
                Installiert:
                @if ($status['current_is_release'])
                    <a href="{{ $currentReleaseUrl }}" target="_blank" rel="noopener noreferrer"
                       class="font-medium text-gray-900 hover:text-laravel-700">{{ $status['current'] }}</a>
                @else
                    <span class="font-medium text-gray-900">{{ $status['current'] }}</span>
                @endif

                @if ($status['latest'])
                    &nbsp;&middot;&nbsp; Neueste:
                    <a href="{{ $latestReleaseUrl }}" target="_blank" rel="noopener noreferrer"
                       class="font-medium text-gray-900 hover:text-laravel-700">{{ $status['latest'] }}</a>
                @endif
            </div>

            @if ($status['checked_at'])
                <div class="text-xs text-gray-400">Zuletzt geprüft {{ \Illuminate\Support\Carbon::parse($status['checked_at'])->diffForHumans() }}</div>
            @endif

            @if ($status['error'])
                <div class="text-xs text-red-600">{{ $status['error'] }}</div>
            @elseif (! $status['current_is_release'])
                <div class="text-xs text-amber-600">Es läuft eine Entwicklungsversion &ndash; ein automatischer Versionsvergleich ist nicht möglich.</div>
            @endif
        </div>

        <a href="{{ $latestReleaseUrl }}" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center gap-1.5 text-sm font-medium text-laravel-600 hover:text-laravel-700 whitespace-nowrap shrink-0">
            <x-icon name="github" class="h-4 w-4" /> Releases ansehen
        </a>
    </div>

    @if ($status['update_available'])
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-center gap-2">
            <x-icon name="sparkles" class="h-4 w-4 text-amber-500 shrink-0" />
            <span><span class="font-medium">{{ $status['latest'] }}</span> ist verfügbar. Changelog und Anleitung findest du unten.</span>
        </div>
    @endif
</x-card>

{{-- Changelog + Update-Anleitung, hinter einem Info-Icon aufklappbar --}}
<div x-data="{ open: {{ $status['update_available'] ? 'true' : 'false' }} }">
    <button type="button" @click="open = ! open"
            class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 mb-3">
        <x-icon name="info" class="h-5 w-5 text-laravel-600 shrink-0" />
        <span>Changelog &amp; Anleitung zum Aktualisieren</span>
        <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
        </svg>
    </button>

    <div x-show="open" x-transition style="display:none" class="space-y-6">
        {{-- Changelog --}}
        <x-card title="Changelog">
            @if ($status['release'] && ! empty($status['release']['published_at']))
                <p class="-mt-2 mb-3 text-xs text-gray-400">
                    {{ $status['latest'] }} &mdash; veröffentlicht {{ \Illuminate\Support\Carbon::parse($status['release']['published_at'])->isoFormat('LL') }}
                </p>
            @elseif ($status['latest'])
                <p class="-mt-2 mb-3 text-xs text-gray-400">{{ $status['latest'] }}</p>
            @endif

            @if ($changelogHtml)
                <div class="changelog-body text-sm leading-relaxed">
                    {!! $changelogHtml !!}
                </div>
            @elseif ($status['latest'])
                <p class="text-sm text-gray-500">
                    Für {{ $status['latest'] }} sind keine Release-Notes hinterlegt &ndash;
                    <a href="{{ $latestReleaseUrl }}" target="_blank" rel="noopener noreferrer" class="text-laravel-600 underline">auf GitHub ansehen</a>.
                </p>
            @else
                <p class="text-sm text-gray-500">Es wurde noch keine Prüfung durchgeführt. Nutze &bdquo;Jetzt prüfen&ldquo;.</p>
            @endif
        </x-card>

        {{-- Update-Anleitung --}}
        <x-card title="So aktualisierst du">
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
                    <p>Image-Tag in <code class="rounded bg-gray-100 px-1">.env</code> (<code>APP_IMAGE</code>) auf die neue Version setzen
                        &ndash; oder bei <code>:latest</code> bleiben &ndash; und dann:</p>
                    <pre class="overflow-x-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100"><code>docker compose pull
docker compose up -d</code></pre>
                    <p class="text-xs text-gray-400">Migrationen laufen beim Start automatisch (<code>AUTO_MIGRATE=true</code>) bzw. manuell mit
                        <code>docker compose exec app php artisan migrate --force</code>.</p>
                </div>

                <div x-show="tab === 'git'" style="display:none" class="space-y-3 text-sm text-gray-600">
                    <p>Auf dem Server &ndash; Zero-Downtime-Release über <code>deploy/update.sh</code>:</p>
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
</div>
@endsection
