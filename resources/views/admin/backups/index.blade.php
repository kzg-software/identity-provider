@extends('layouts.admin')

@php
    $openCreate = $errors->has('password') || old('_form') === 'create';
    $openRestore = $errors->has('backup') || $errors->has('confirm') || old('_form') === 'restore';
@endphp

@section('admin-content')
<x-page-header
    title="Datensicherung"
    description="Das gesamte System als eine verschlüsselte Datei sichern und bei Bedarf daraus wiederherstellen." />

<div class="grid gap-6 lg:grid-cols-2"
     x-data="{ create: {{ $openCreate ? 'true' : 'false' }}, restore: {{ $openRestore ? 'true' : 'false' }} }">

    {{-- Sicherung erstellen --}}
    <x-card>
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-laravel-600 text-white">
                <x-icon name="download" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900">Sicherung erstellen</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Enthält die Datenbank, die Konfiguration (.env) und alle hochgeladenen Dateien wie Logo,
                    Favicon und Login-Hintergrund. Die Datei wird mit einem Passwort verschlüsselt.
                </p>
                <div class="mt-4">
                    <x-button type="button" @click="create = true">Sicherung erstellen</x-button>
                </div>
            </div>
        </div>
    </x-card>

    {{-- Sicherung wiederherstellen --}}
    <x-card>
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-700 text-white">
                <x-icon name="arrow-path" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900">Sicherung wiederherstellen</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Spielt eine Sicherungsdatei ein und ersetzt dabei alle aktuellen Daten. Danach wirst du
                    abgemeldet und musst dich neu anmelden.
                </p>
                <div class="mt-4">
                    <x-button type="button" variant="secondary" @click="restore = true">Wiederherstellen</x-button>
                </div>
            </div>
        </div>
    </x-card>

    {{-- Modal: erstellen --}}
    <div x-show="create" x-cloak style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="create = false">
        <div class="fixed inset-0 bg-gray-900/50" @click="create = false"></div>
        <div class="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 class="mb-1 text-base font-semibold text-gray-900">Sicherung erstellen</h3>
            <p class="mb-4 text-sm text-gray-600">Vergib ein Passwort für die Sicherungsdatei. Du brauchst es zum Wiederherstellen, es lässt sich nicht zurücksetzen.</p>

            <form method="POST" action="{{ route('admin.backups.download') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="_form" value="create">

                <div>
                    <x-input-label value="Passwort für die Sicherungsdatei" />
                    <x-input type="password" name="password" required autocomplete="new-password" />
                </div>
                <div>
                    <x-input-label value="Passwort wiederholen" />
                    <x-input type="password" name="password_confirmation" required autocomplete="new-password" />
                </div>
                <div>
                    <x-input-label value="Dein Kontopasswort zur Bestätigung" />
                    <x-input type="password" name="current_password" required autocomplete="current-password" />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <x-button type="button" variant="secondary" @click="create = false">Abbrechen</x-button>
                    <x-button type="submit">Sicherung herunterladen</x-button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal: wiederherstellen --}}
    <div x-show="restore" x-cloak style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="restore = false">
        <div class="fixed inset-0 bg-gray-900/50" @click="restore = false"></div>
        <div class="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 class="mb-1 text-base font-semibold text-gray-900">Sicherung wiederherstellen</h3>
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Alle aktuellen Daten dieses Systems werden ersetzt. Das lässt sich nicht rückgängig machen.
            </div>

            <form method="POST" action="{{ route('admin.backups.restore') }}" enctype="multipart/form-data" class="space-y-4"
                  x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <input type="hidden" name="_form" value="restore">

                <div>
                    <x-input-label value="Sicherungsdatei (.authbak)" />
                    <input type="file" name="backup" accept=".authbak" required
                           class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
                    <p class="mt-1 text-xs text-gray-400">Maximale Dateigröße auf diesem Server: {{ \App\Support\UploadLimits::humanMax() }}.</p>
                </div>
                <div>
                    <x-input-label value="Passwort der Sicherung" />
                    <x-input type="password" name="password" required autocomplete="off" />
                </div>
                <div>
                    <x-input-label value="Dein Kontopasswort zur Bestätigung" />
                    <x-input type="password" name="current_password" required autocomplete="current-password" />
                </div>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <x-checkbox name="confirm" value="1" class="mt-0.5" />
                    <span>Mir ist klar, dass die aktuellen Daten überschrieben werden.</span>
                </label>

                <div class="flex justify-end gap-3 pt-2">
                    <x-button type="button" variant="secondary" @click="restore = false">Abbrechen</x-button>
                    <x-button type="submit" variant="danger" x-bind:disabled="busy">
                        <span x-show="!busy">Wiederherstellen</span>
                        <span x-show="busy" x-cloak>Wird eingespielt …</span>
                    </x-button>
                </div>
            </form>
        </div>
    </div>
</div>

@php
    $abTarget = old('target', $auto['auto_backup_target'] ?: 'local');
    $abEnabled = old('enabled', $auto['auto_backup_enabled'] ?? '0') === '1';
    $lastRun = $auto['auto_backup_last_run'] ?? null;
    $lastError = $auto['auto_backup_last_error'] ?? null;
    $lastFile = $auto['auto_backup_last_file'] ?? null;
@endphp

<x-card class="mt-6" title="Automatische Sicherung"
        description="Erstellt regelmäßig eine Sicherung und lädt sie an ein Ziel hoch (lokales Verzeichnis, S3, FTP oder SFTP). Alte Sicherungen werden gemäß der Aufbewahrungsregel entfernt.">

    <div class="mb-4 rounded-md border px-4 py-3 text-sm
                {{ $lastError ? 'border-red-200 bg-red-50 text-red-800' : ($lastRun ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-gray-200 bg-gray-50 text-gray-600') }}">
        @if ($lastError)
            Letzter Lauf {{ \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans() }} fehlgeschlagen: {{ $lastError }}
        @elseif ($lastRun)
            Letzte Sicherung {{ \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans() }}@if ($lastFile), Datei {{ $lastFile }}@endif.
        @else
            Es wurde noch keine automatische Sicherung ausgeführt.
        @endif
    </div>

    <form method="POST" action="{{ route('admin.backups.auto.update') }}" class="space-y-5"
          x-data="{ target: '{{ $abTarget }}' }">
        @csrf
        @method('PUT')

        <label class="flex cursor-pointer items-start gap-3">
            <input type="hidden" name="enabled" value="0">
            <x-checkbox name="enabled" value="1" class="mt-0.5" :checked="$abEnabled" />
            <span class="text-sm font-medium text-gray-900">Automatische Sicherung aktiv</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-input-label value="Häufigkeit" />
                <x-select name="frequency">
                    <option value="daily" @selected(old('frequency', $auto['auto_backup_frequency'] ?: 'daily') === 'daily')>Täglich</option>
                    <option value="weekly" @selected(old('frequency', $auto['auto_backup_frequency']) === 'weekly')>Wöchentlich (montags)</option>
                </x-select>
            </div>
            <div>
                <x-input-label value="Uhrzeit" />
                <x-input type="time" name="time" value="{{ old('time', $auto['auto_backup_time'] ?: '03:00') }}" />
            </div>
            <div>
                <x-input-label value="Aufbewahrung" />
                <div class="flex items-center gap-2">
                    <x-input type="number" name="keep" min="0" max="365" class="!w-24"
                             value="{{ old('keep', $auto['auto_backup_keep'] ?: '7') }}" />
                    <span class="text-sm text-gray-500">Stück (0 = alle)</span>
                </div>
            </div>
        </div>

        <div>
            <x-input-label value="Passwort für die Sicherungsdateien" />
            <x-input type="password" name="archive_password" autocomplete="new-password"
                     placeholder="{{ $hasArchivePassword ? '•••••••• (unverändert)' : 'mindestens 10 Zeichen' }}" />
            <p class="mt-1 text-xs text-gray-500">Verschlüsselt jede automatische Sicherung. Wird verschlüsselt gespeichert. Getrennt von den Sicherungen notieren, ohne dieses Passwort ist keine Wiederherstellung möglich.</p>
        </div>

        <div class="border-t border-gray-100 pt-5">
            <x-input-label value="Ziel" />
            <x-select name="target" x-model="target" class="!max-w-xs">
                <option value="local">Lokales Verzeichnis</option>
                <option value="s3">S3 (AWS oder kompatibel)</option>
                <option value="ftp">FTP</option>
                <option value="sftp">SFTP</option>
            </x-select>

            <div class="mt-4 space-y-4">
                <div>
                    <x-input-label>
                        <span x-show="target === 'local'">Verzeichnis</span>
                        <span x-show="target === 's3'" x-cloak>Pfad-Präfix im Bucket (optional)</span>
                        <span x-show="target === 'ftp' || target === 'sftp'" x-cloak>Verzeichnis auf dem Server</span>
                    </x-input-label>
                    <x-input type="text" name="dir" value="{{ old('dir', $auto['auto_backup_dir']) }}"
                             placeholder="z. B. /var/backups/idp oder backups" />
                    <p class="mt-1 text-xs text-gray-500" x-show="target === 'local'">Absoluter Pfad, oder relativ zu <code class="rounded bg-gray-100 px-1">storage/app/</code>. Leer = <code class="rounded bg-gray-100 px-1">storage/app/private/backups</code>.</p>
                </div>

                {{-- FTP / SFTP --}}
                <div x-show="target === 'ftp' || target === 'sftp'" x-cloak class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label value="Host" />
                        <x-input type="text" name="host" value="{{ old('host', $auto['auto_backup_host']) }}" placeholder="backup.firma.local" />
                    </div>
                    <div>
                        <x-input-label value="Port" />
                        <x-input type="number" name="port" class="!w-28" value="{{ old('port', $auto['auto_backup_port']) }}" placeholder="21 / 22" />
                    </div>
                    <div>
                        <x-input-label value="Benutzername" />
                        <x-input type="text" name="username" value="{{ old('username', $auto['auto_backup_username']) }}" autocomplete="off" />
                    </div>
                    <div>
                        <x-input-label value="Passwort" />
                        <x-input type="password" name="remote_password" autocomplete="new-password"
                                 placeholder="{{ $hasRemotePassword ? '•••••••• (unverändert)' : '' }}" />
                    </div>
                    <label class="flex items-center gap-2.5 text-sm text-gray-700" x-show="target === 'ftp'">
                        <input type="hidden" name="ftp_ssl" value="0">
                        <x-checkbox name="ftp_ssl" value="1" :checked="old('ftp_ssl', $auto['auto_backup_ftp_ssl']) === '1'" />
                        Explizites TLS (FTPS)
                    </label>
                </div>

                {{-- S3 --}}
                <div x-show="target === 's3'" x-cloak class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label value="Bucket" />
                        <x-input type="text" name="s3_bucket" value="{{ old('s3_bucket', $auto['auto_backup_s3_bucket']) }}" />
                    </div>
                    <div>
                        <x-input-label value="Region" />
                        <x-input type="text" name="s3_region" value="{{ old('s3_region', $auto['auto_backup_s3_region'] ?: 'us-east-1') }}" />
                    </div>
                    <div>
                        <x-input-label value="Access Key" />
                        <x-input type="text" name="s3_key" value="{{ old('s3_key', $auto['auto_backup_s3_key']) }}" autocomplete="off" />
                    </div>
                    <div>
                        <x-input-label value="Secret Key" />
                        <x-input type="password" name="s3_secret" autocomplete="new-password"
                                 placeholder="{{ $hasS3Secret ? '•••••••• (unverändert)' : '' }}" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label value="Endpoint (optional, für S3-kompatible Speicher)" />
                        <x-input type="text" name="s3_endpoint" value="{{ old('s3_endpoint', $auto['auto_backup_s3_endpoint']) }}" placeholder="https://s3.eu-central-1.wasabisys.com" />
                    </div>
                    <label class="flex items-center gap-2.5 text-sm text-gray-700 sm:col-span-2">
                        <input type="hidden" name="s3_path_style" value="0">
                        <x-checkbox name="s3_path_style" value="1" :checked="old('s3_path_style', $auto['auto_backup_s3_path_style']) === '1'" />
                        Path-Style-Endpoint verwenden (MinIO, Ceph, ältere Setups)
                    </label>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-100 pt-5">
            <x-button type="submit">Speichern</x-button>
        </div>
    </form>

    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
        <span class="w-full text-xs text-gray-500">Nutzen die zuletzt gespeicherten Einstellungen. Erst speichern, dann testen oder sofort sichern.</span>
        <form method="POST" action="{{ route('admin.backups.auto.test') }}">
            @csrf
            <x-button type="submit" variant="secondary" size="sm">Verbindung testen</x-button>
        </form>
        <x-confirm-form :action="route('admin.backups.auto.run')" method="POST" icon="download" variant="secondary" size="sm"
                        title="Jetzt sichern"
                        message="Es wird sofort eine Sicherung erstellt und an das gespeicherte Ziel hochgeladen. Das kann je nach Datenmenge etwas dauern."
                        label="Jetzt sichern" />
    </div>
</x-card>

<x-card class="mt-6" title="Gut zu wissen">
    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600">
        <li>Bewahre das Sicherungs-Passwort getrennt von der Datei auf. Ohne Passwort ist die Sicherung nicht lesbar.</li>
        <li>Die Sicherung enthält die Konfiguration inklusive des Anwendungsschlüssels. Damit lassen sich auch verschlüsselte Werte (zum Beispiel AD-Bind-Passwörter) wiederherstellen.</li>
        <li>Beim Wiederherstellen gilt das Upload-Limit dieses Servers ({{ \App\Support\UploadLimits::humanMax() }}). Größere Sicherungen brauchen höhere PHP-Werte für <code class="rounded bg-gray-100 px-1">upload_max_filesize</code> und <code class="rounded bg-gray-100 px-1">post_max_size</code>.</li>
        <li>Vor jeder Wiederherstellung wird der bisherige Stand unter <code class="rounded bg-gray-100 px-1">storage/framework/backups/</code> als Rücksicherung abgelegt.</li>
        <li>SQLite wird als Dateikopie gesichert, MySQL und MariaDB als Tabellenexport. Ein Wechsel des Datenbanktyps über eine Sicherung ist nicht möglich.</li>
    </ul>
</x-card>
@endsection
