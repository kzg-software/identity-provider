@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Lokalen Benutzer anlegen"
    :back="route('admin.users.index')" back-label="Alle Benutzer"
    description="Ein Konto direkt in diesem System, unabhängig von Active Directory oder LDAP. Gut für Administratoren und Dienstkonten." />

<x-card class="max-w-lg">
    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4">
        @csrf
        <div>
            <x-input-label value="Benutzername" />
            <x-input type="text" name="username" value="{{ old('username') }}" required autofocus />
            <p class="mt-1 text-xs text-gray-500">Zum Anmelden. Kann nachträglich nicht mehr geändert werden.</p>
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
            <x-input-label value="E-Mail" />
            <x-input type="email" name="email" value="{{ old('email') }}" required />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <x-input-label value="Passwort" />
                <x-input type="password" name="password" required />
            </div>
            <div>
                <x-input-label value="Passwort wiederholen" />
                <x-input type="password" name="password_confirmation" required />
            </div>
        </div>
        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
            <x-checkbox name="is_admin" value="1" :checked="old('is_admin')" />
            Administrator
        </label>
        <p class="-mt-2 text-xs text-gray-500">Administratoren sehen den kompletten Adminbereich und können alles verwalten.</p>

        <div class="flex gap-3 pt-2">
            <x-button type="submit">Benutzer anlegen</x-button>
            <x-button tag="a" href="{{ route('admin.users.index') }}" variant="link">Abbrechen</x-button>
        </div>
    </form>
</x-card>
@endsection
