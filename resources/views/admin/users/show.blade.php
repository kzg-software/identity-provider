@extends('layouts.admin')

@section('admin-content')
<a href="{{ route('admin.users.index') }}" class="text-sm text-laravel-600 hover:text-laravel-700">&larr; zurück</a>
<h1 class="text-2xl font-semibold text-gray-900 mt-1 mb-6">{{ $user->name }}</h1>

<div class="grid md:grid-cols-2 gap-4">
    <div class="space-y-4">
        <x-card title="Allgemein">
            <x-dl :rows="[
                'Benutzername' => $user->username,
                'E-Mail' => $user->email,
                'Domain' => $user->domain,
                'Auth-Quelle' => $user->auth_source,
                'Letzter Login' => $user->last_login_at,
            ]" />
        </x-card>

        @if ($user->auth_source === 'local')
            <x-card title="Passwort zurücksetzen">
                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="space-y-3">
                    @csrf
                    <x-input type="password" name="password" placeholder="Neues Passwort" required />
                    <x-input type="password" name="password_confirmation" placeholder="Bestätigen" required />
                    <x-button type="submit" size="sm">Zurücksetzen</x-button>
                </form>
            </x-card>
        @endif

        @if ($user->id !== auth()->id())
            <x-card title="Benutzer löschen">
                @if ($user->auth_source === 'local')
                    <p class="text-sm text-gray-500 mb-3">Entfernt das Konto und alle Sitzungen dauerhaft.</p>
                @else
                    <p class="text-sm text-gray-500 mb-3">
                        Entfernt den lokalen Datensatz samt Gruppen-Zuordnung. Existiert das Konto weiterhin im
                        Verzeichnis, kann die nächste Synchronisierung es wieder anlegen. Dauerhaft entfernen:
                        im Verzeichnis selbst löschen oder für dieses Verzeichnis "Fehlende Benutzer: löschen" setzen.
                    </p>
                @endif
                <x-confirm-form :action="route('admin.users.destroy', $user)"
                                message="{{ $user->name }} wirklich entfernen?"
                                label="Benutzer löschen" size="sm" />
            </x-card>
        @endif
    </div>

    <div class="space-y-4">
        <x-card title="Active Directory">
            <x-dl :rows="[
                'DN' => $user->distinguished_name,
                'SID' => $user->sid,
                'Object GUID' => $user->object_guid,
                'UPN' => $user->upn,
                'Abteilung' => $user->department,
                'Position' => $user->position,
            ]" />
        </x-card>

        <x-card title="Sessions" :padding="false">
            <ul class="divide-y divide-gray-100">
                @forelse ($user->sessions as $session)
                    <li class="flex justify-between px-4 py-2 text-sm">
                        <span class="text-gray-700">{{ $session->ip_address }} — {{ $session->login_method }}</span>
                        <span class="text-gray-400">{{ $session->last_activity_at }}</span>
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-gray-400">Keine aktiven Sessions.</li>
                @endforelse
            </ul>
        </x-card>
    </div>
</div>
@endsection
