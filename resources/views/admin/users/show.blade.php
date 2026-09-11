@extends('layouts.admin')

@section('admin-content')
<x-page-header :title="$user->name" :back="route('admin.users.index')" back-label="Alle Benutzer">
    <x-slot:actions>
        <x-badge>{{ $user->auth_source }}</x-badge>
        @if ($user->is_admin)<x-badge color="laravel">Administrator</x-badge>@endif
        @if ($user->is_active)
            <x-badge color="green">aktiv</x-badge>
        @else
            <x-badge color="red">gesperrt</x-badge>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="grid gap-6 md:grid-cols-2">
    <div class="space-y-6">
        <x-card title="Allgemein">
            @if ($user->isLocal())
                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Benutzername</label>
                        <x-input name="username" value="{{ old('username', $user->username) }}" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">E-Mail</label>
                        <x-input type="email" name="email" value="{{ old('email', $user->email) }}" required />
                    </div>
                    <x-button type="submit" size="sm">Speichern</x-button>
                </form>
            @else
                <x-dl :rows="[
                    'Benutzername' => $user->username,
                    'E-Mail' => $user->email,
                ]" />
                <p class="mt-3 text-xs text-gray-400">Dieses Konto kommt aus einem Verzeichnis. Benutzername und E-Mail werden von dort synchronisiert und lassen sich hier nicht ändern.</p>
            @endif

            <x-dl class="mt-4" :rows="[
                'Domain' => $user->domain,
                'Auth-Quelle' => $user->auth_source,
                'Rollen' => $user->effectiveRoles() ? implode(', ', $user->effectiveRoles()) : '–',
                'Letzter Login' => $user->last_login_at,
            ]" />
        </x-card>

        @if ($user->id !== auth()->id())
            <x-card title="Administrator-Rechte"
                    description="Administratoren sehen diesen Bereich und können alles verwalten.">
                @if ($user->is_admin)
                    <p class="mb-3 text-sm text-gray-600">
                        @if ($user->adminFromGroupMapping())
                            Dieser Benutzer ist Administrator, abgeleitet aus einem Gruppen-Mapping.
                        @else
                            Dieser Benutzer ist Administrator.
                        @endif
                    </p>
                @else
                    <p class="mb-3 text-sm text-gray-500">Dieser Benutzer ist kein Administrator.</p>
                @endif

                <form method="POST" action="{{ route('admin.users.toggle-admin', $user) }}">
                    @csrf
                    @if ($user->is_admin)
                        <x-button type="submit" variant="secondary" size="sm">Administrator-Rechte entziehen</x-button>
                        @if ($user->adminFromGroupMapping())
                            <p class="mt-2 text-xs text-gray-400">Solange die Gruppe im Rollen-Mapping auf „admin" zeigt, wird der Benutzer beim nächsten Login wieder Administrator.</p>
                        @endif
                    @else
                        <x-button type="submit" size="sm">Zum Administrator machen</x-button>
                        <p class="mt-2 text-xs text-gray-400">Vergibt die Rolle „admin" fest für diesen Benutzer. Bleibt bei einer Verzeichnis-Synchronisierung erhalten.</p>
                    @endif
                </form>
            </x-card>
        @endif

        @if ($user->auth_source === 'local')
            <x-card title="Passwort zurücksetzen"
                    description="Setzt sofort ein neues Passwort. Der Benutzer wird nicht benachrichtigt.">
                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="space-y-3">
                    @csrf
                    <x-input type="password" name="password" placeholder="Neues Passwort" required />
                    <x-input type="password" name="password_confirmation" placeholder="Passwort wiederholen" required />
                    <p class="text-xs text-gray-500">{{ \App\Support\SecuritySettings::passwordHint() }}</p>
                    <x-button type="submit" size="sm">Zurücksetzen</x-button>
                </form>
            </x-card>
        @endif
    </div>

    <div class="space-y-6">
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

        <x-card title="Sitzungen" :padding="false">
            <ul class="divide-y divide-gray-100">
                @forelse ($user->sessions as $session)
                    <li class="flex justify-between px-4 py-2.5 text-sm">
                        <span class="text-gray-700">{{ $session->ip_address }}, {{ $session->login_method }}</span>
                        <span class="text-gray-400">{{ $session->last_activity_at }}</span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-gray-400">Keine aktiven Sitzungen.</li>
                @endforelse
            </ul>
        </x-card>

        <x-card title="Zwei-Faktor-Authentisierung"
                description="Zur Kontowiederherstellung, wenn der Benutzer den Zugang zu seinen Faktoren verloren hat.">
            <x-dl :rows="[
                'Authenticator-App' => $user->twoFactor?->hasTotp() ? 'aktiv' : 'nicht eingerichtet',
                'Wiederherstellungscodes' => (string) count($user->twoFactor?->recoveryCodes() ?? []).' übrig',
            ]" />

            @if ($user->webauthnCredentials->isNotEmpty())
                <ul class="mt-3 divide-y divide-gray-100 border-t border-gray-100">
                    @foreach ($user->webauthnCredentials as $credential)
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <span class="text-gray-700 truncate">
                                <x-icon name="key" class="inline h-4 w-4 text-gray-400" /> {{ $credential->name }}
                            </span>
                            <x-confirm-form :action="route('admin.users.webauthn.destroy', [$user, $credential])"
                                            message="Passkey {{ $credential->name }} entfernen?"
                                            label="Entfernen" size="sm" icon="trash" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-gray-400">Keine Passkeys hinterlegt.</p>
            @endif

            @if ($user->twoFactor?->hasTotp() || $user->webauthnCredentials->isNotEmpty())
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <x-confirm-form :action="route('admin.users.two-factor.reset', $user)"
                                    method="POST"
                                    title="Zwei-Faktor zurücksetzen"
                                    message="Entfernt Authenticator-App, alle Passkeys und Wiederherstellungscodes von {{ $user->name }}. Der Benutzer muss die Zwei-Faktor-Authentisierung danach neu einrichten."
                                    label="Zwei-Faktor zurücksetzen" variant="danger" size="sm" icon="arrow-path" />
                </div>
            @endif
        </x-card>
    </div>
</div>

@if ($user->id !== auth()->id())
    <div class="mt-6">
        <x-danger-zone>
            @if ($user->auth_source === 'local')
                <p class="w-full text-sm text-red-700">Entfernt das Konto und alle Sitzungen dauerhaft.</p>
            @else
                <p class="w-full text-sm text-red-700">
                    Entfernt den lokalen Datensatz samt Gruppen-Zuordnung. Existiert das Konto weiterhin im
                    Verzeichnis, kann die nächste Synchronisierung es wieder anlegen. Dauerhaft entfernen:
                    im Verzeichnis selbst löschen oder für das Verzeichnis „Fehlende Benutzer: löschen" setzen.
                </p>
            @endif
            <x-confirm-form :action="route('admin.users.destroy', $user)"
                            message="{{ $user->name }} wirklich entfernen?"
                            label="Benutzer löschen" size="sm" />
        </x-danger-zone>
    </div>
@endif
@endsection
