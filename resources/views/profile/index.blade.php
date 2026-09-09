@extends('layouts.admin')

@section('admin-content')
@php
    $authSourceLabel = $user->isLocal() ? 'Lokales Konto' : 'Verzeichniskonto';
@endphp

<div class="max-w-4xl space-y-6">
    <div>
        <h1 class="mb-1 text-2xl font-semibold text-gray-900">Mein Account</h1>
        <p class="text-gray-500">Deine Kontodaten und Einstellungen an einem Ort.</p>
    </div>

    {{-- Kontodaten --}}
    <x-card title="Kontodaten" icon="user">
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-gray-400">Name</dt>
                <dd class="text-sm font-medium text-gray-900">{{ $user->display_name ?: $user->name }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Benutzername</dt>
                <dd class="text-sm font-medium text-gray-900">{{ $user->username }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">E-Mail</dt>
                <dd class="text-sm font-medium text-gray-900">{{ $user->email ?: 'Nicht hinterlegt' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400">Kontoart</dt>
                <dd class="text-sm font-medium text-gray-900">{{ $authSourceLabel }}</dd>
            </div>
        </dl>
        @unless ($user->isLocal())
            <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-400">
                Diese Angaben stammen aus dem verbundenen Verzeichnis und werden dort gepflegt.
            </p>
        @endunless
    </x-card>

    {{-- Bereiche --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-account-tile :href="route('profile.security')" title="Sicherheit" icon="shield-check">
            {{ $twoFactorEnabled ? 'Zweiter Faktor aktiv' : 'Kein zweiter Faktor' }},
            {{ $passkeyCount }} {{ $passkeyCount === 1 ? 'Passkey' : 'Passkeys' }}
        </x-account-tile>

        <x-account-tile :href="route('profile.apps')" title="Verbundene Anwendungen" icon="grid">
            {{ $connectedAppCount }} {{ $connectedAppCount === 1 ? 'Anwendung' : 'Anwendungen' }} mit Zugriff
        </x-account-tile>

        <x-account-tile :href="route('profile.sessions')" title="Meine Sitzungen" icon="monitor">
            {{ $activeSessionCount }} {{ $activeSessionCount === 1 ? 'aktive Sitzung' : 'aktive Sitzungen' }}
        </x-account-tile>
    </div>
</div>
@endsection
