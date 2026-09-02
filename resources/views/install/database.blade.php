@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Datenbank</h2>
<p class="text-sm text-gray-500 mb-5">Wohin das System seine Daten schreibt. Erst die Verbindung testen, dann speichern. Beim Speichern werden die Tabellen angelegt.</p>

@php $connection = old('connection', 'sqlite'); @endphp

<form method="POST" action="{{ route('install.database.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Datenbanktyp" />
        <x-select name="connection">
            <option value="sqlite" @selected($connection === 'sqlite')>SQLite (Datei, nur für Tests)</option>
            <option value="mysql" @selected($connection === 'mysql')>MySQL</option>
            <option value="mariadb" @selected($connection === 'mariadb')>MariaDB</option>
        </x-select>
        <p class="mt-1 text-xs text-gray-500">Für den Produktivbetrieb MySQL oder MariaDB wählen. SQLite eignet sich nur zum Ausprobieren.</p>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div class="col-span-2">
            <x-input-label value="Host" />
            <x-input type="text" name="host" value="{{ old('host', '127.0.0.1') }}" />
        </div>
        <div>
            <x-input-label value="Port" />
            <x-input type="number" name="port" value="{{ old('port', 3306) }}" />
        </div>
    </div>

    <div>
        <x-input-label value="Datenbankname (bei SQLite: Dateiname)" />
        <x-input type="text" name="database" value="{{ old('database', 'database.sqlite') }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Benutzer" />
            <x-input type="text" name="username" value="{{ old('username') }}" />
        </div>
        <div>
            <x-input-label value="Passwort" />
            <x-input type="password" name="password" value="{{ old('password') }}" />
        </div>
    </div>

    <div class="flex flex-wrap gap-3 pt-2">
        <x-button type="submit" variant="secondary" formaction="{{ route('install.database.test') }}">Verbindung testen</x-button>
        <x-button type="submit">Speichern und Tabellen anlegen</x-button>
    </div>
</form>
@endsection
