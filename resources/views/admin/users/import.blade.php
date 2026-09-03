@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Benutzer importieren"
    :back="route('admin.users.index')" back-label="Alle Benutzer"
    description="Legt lokale Konten aus einer CSV-Datei an oder aktualisiert sie. Verzeichnis-Konten sind davon nicht betroffen." />

@php($result = session('import_result'))
@if ($result)
    <x-card class="mb-6" title="Ergebnis">
        <div class="flex flex-wrap gap-4 text-sm">
            <span class="rounded-md bg-emerald-50 px-3 py-1.5 text-emerald-800">{{ $result['created'] }} angelegt</span>
            <span class="rounded-md bg-blue-50 px-3 py-1.5 text-blue-800">{{ $result['updated'] }} aktualisiert</span>
            <span class="rounded-md bg-gray-100 px-3 py-1.5 text-gray-700">{{ count($result['skipped']) }} übersprungen</span>
        </div>

        @if (! empty($result['generated']))
            <div class="mt-4">
                <p class="text-sm font-medium text-gray-900">Automatisch vergebene Passwörter (nur jetzt sichtbar):</p>
                <div class="mt-2 overflow-x-auto">
                    <table class="text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($result['generated'] as $username => $pw)
                                <tr>
                                    <td class="py-1 pr-6 text-gray-600">{{ $username }}</td>
                                    <td class="py-1 font-mono">{{ $pw }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-gray-500">Bitte den Betroffenen sicher übermitteln. Nach dem Verlassen der Seite sind sie nicht mehr abrufbar.</p>
            </div>
        @endif

        @if (! empty($result['skipped']))
            <div class="mt-4">
                <p class="text-sm font-medium text-gray-900">Übersprungene Zeilen:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-gray-600">
                    @foreach ($result['skipped'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-card>
@endif

<x-card class="max-w-2xl">
    <form method="POST" action="{{ route('admin.users.import.run') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <x-input-label value="CSV-Datei" />
            <input type="file" name="file" accept=".csv,text/csv" required
                   class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
        </div>

        <div class="rounded-md bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
            <p class="font-medium text-gray-700">Aufbau der CSV (Kopfzeile erforderlich, Spaltenreihenfolge egal):</p>
            <p class="mt-1">Pflicht: <code class="rounded bg-white px-1">username</code>, <code class="rounded bg-white px-1">email</code></p>
            <p>Optional: <code class="rounded bg-white px-1">first_name</code>, <code class="rounded bg-white px-1">last_name</code>, <code class="rounded bg-white px-1">name</code>, <code class="rounded bg-white px-1">is_admin</code>, <code class="rounded bg-white px-1">is_active</code>, <code class="rounded bg-white px-1">password</code></p>
            <ul class="mt-2 list-disc space-y-0.5 pl-4">
                <li>Gibt es den Benutzernamen schon (und ist lokal), wird das Konto aktualisiert. Das Passwort bleibt unangetastet.</li>
                <li>Ohne <code class="rounded bg-white px-1">password</code>-Spalte wird ein zufälliges Passwort erzeugt und im Ergebnis angezeigt.</li>
                <li>Ein gesetztes Passwort muss die Passwort-Richtlinie erfüllen.</li>
                <li><code class="rounded bg-white px-1">is_admin</code> / <code class="rounded bg-white px-1">is_active</code>: 1, true, ja oder x zählt als „an".</li>
            </ul>
        </div>

        <x-button type="submit">Importieren</x-button>
    </form>
</x-card>
@endsection
