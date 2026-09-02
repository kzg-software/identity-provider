@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Administrator</h2>
<p class="text-sm text-gray-500 mb-4">Ein lokales Konto mit vollen Rechten. Es funktioniert unabhängig von Active Directory und dient als Zugang, wenn die AD-Anmeldung einmal nicht erreichbar ist. Bitte das Passwort sicher aufbewahren.</p>

<form method="POST" action="{{ route('install.admin.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Benutzername" />
        <x-input type="text" name="username" value="{{ old('username') }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Vorname" />
            <x-input type="text" name="first_name" value="{{ old('first_name') }}" required />
        </div>
        <div>
            <x-input-label value="Nachname" />
            <x-input type="text" name="last_name" value="{{ old('last_name') }}" required />
        </div>
    </div>

    <div>
        <x-input-label value="E-Mail-Adresse" />
        <x-input type="email" name="email" value="{{ old('email') }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Passwort" />
            <x-input type="password" name="password" required />
        </div>
        <div>
            <x-input-label value="Passwort bestätigen" />
            <x-input type="password" name="password_confirmation" required />
        </div>
    </div>

    <x-button type="submit">Weiter</x-button>
</form>
@endsection
