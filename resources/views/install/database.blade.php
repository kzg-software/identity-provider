@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-4">Schritt 2: Datenbank</h2>

@php $connection = old('connection', 'sqlite'); @endphp

<form method="POST" action="{{ route('install.database.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Datenbanktyp" />
        <x-select name="connection">
            <option value="sqlite" @selected($connection === 'sqlite')>SQLite (Entwicklung)</option>
            <option value="mysql" @selected($connection === 'mysql')>MySQL</option>
            <option value="mariadb" @selected($connection === 'mariadb')>MariaDB</option>
        </x-select>
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
        <x-input-label value="Datenbankname / SQLite-Dateiname" />
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

    <div class="flex gap-3 pt-2">
        <x-button type="submit" variant="secondary" formaction="{{ route('install.database.test') }}">Verbindung testen</x-button>
        <x-button type="submit">Weiter &amp; Migrationen ausführen</x-button>
    </div>
</form>
@endsection
