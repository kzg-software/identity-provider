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
