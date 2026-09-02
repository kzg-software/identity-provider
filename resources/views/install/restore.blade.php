@extends('layouts.install')

@section('install-content')
<div class="mb-5">
    <a href="{{ route('install.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Zurück zur Auswahl</a>
</div>

<h2 class="text-base font-semibold text-gray-900 mb-1">Aus Sicherung wiederherstellen</h2>
<p class="text-sm text-gray-500 mb-5">
    Laden Sie die Sicherungsdatei (<code class="bg-gray-100 px-1 rounded">.authbak</code>) hoch und geben Sie das
    Passwort ein, mit dem sie erstellt wurde. Datenbank, Konfiguration und alle hochgeladenen Dateien werden
    daraus wiederhergestellt.
</p>

<div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-5">
    Eine bereits vorhandene Einrichtung auf diesem Server wird dabei überschrieben. Nach der Wiederherstellung
    melden Sie sich mit den Zugangsdaten aus der Sicherung an.
</div>

<form method="POST" action="{{ route('install.restore.store') }}" enctype="multipart/form-data" class="space-y-4"
      x-data="{ busy: false }" @submit="busy = true">
    @csrf

    <div>
        <x-input-label value="Sicherungsdatei" />
        <input type="file" name="backup" accept=".authbak" required
               class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
        <p class="mt-1 text-xs text-gray-400">Maximale Dateigröße auf diesem Server: {{ \App\Support\UploadLimits::humanMax() }}.</p>
    </div>

    <div>
        <x-input-label value="Passwort der Sicherung" />
        <x-input type="password" name="password" required autocomplete="off" />
    </div>

    <div class="pt-2">
        <x-button type="submit" x-bind:disabled="busy">
            <span x-show="!busy">Wiederherstellung starten</span>
            <span x-show="busy" x-cloak>Wird eingespielt …</span>
        </x-button>
        <p class="mt-2 text-xs text-gray-400">Das Einspielen kann je nach Datenmenge einige Minuten dauern. Bitte das Fenster nicht schließen.</p>
    </div>
</form>
@endsection
