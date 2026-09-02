@extends('layouts.admin')

@section('admin-content')
<x-page-header :title="$application->name" :back="route('admin.applications.index')" back-label="Alle Anwendungen">
    <x-slot:actions>
        @if ($application->is_active)
            <x-badge color="green">aktiv</x-badge>
        @else
            <x-badge>inaktiv</x-badge>
        @endif
        @if ($application->maintenance_mode)<x-badge color="amber">Wartung</x-badge>@endif
    </x-slot:actions>
</x-page-header>

@if (session('plain_client_secret'))
    <x-alert type="warning">
        <strong>Client Secret (nur jetzt sichtbar):</strong>
        <code class="rounded bg-white/50 px-1">{{ session('plain_client_secret') }}</code>
        <div class="mt-1 text-xs">Jetzt sicher speichern. Nach dem Verlassen der Seite lässt es sich nicht mehr anzeigen.</div>
    </x-alert>
@endif

<div class="space-y-6">
    <x-card title="Grunddaten" description="Name und Beschreibung sieht auch der Benutzer auf der Zustimmungsseite.">
        <form method="POST" action="{{ route('admin.applications.update', $application) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ $application->name }}" required />
            </div>
            <div>
                <x-input-label value="Beschreibung" />
                <x-textarea name="description">{{ $application->description }}</x-textarea>
            </div>
            <div>
                <x-input-label value="Start-URL" />
                <x-input type="url" name="launch_url" value="{{ $application->launch_url }}" placeholder="https://app.example.de" />
                <p class="mt-1 text-xs text-gray-500">Wird Benutzern im Portal als Kachel angezeigt, wenn sie Zugriff auf diese Anwendung haben.</p>
            </div>
            <div>
                <x-input-label value="Bereich (optional)" />
                <x-input type="text" name="category" list="category-suggestions" value="{{ $application->category }}" placeholder="z. B. Allgemein" />
                <datalist id="category-suggestions">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">
                    @endforeach
                </datalist>
                <p class="mt-1 text-xs text-gray-500">Fasst Anwendungen im Portal zu Bereichen zusammen. Gleicher Name gruppiert sie zusammen.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label value="Anmeldung" />
                    <x-select name="login_mode">
                        @foreach (['user_choice' => 'Anmeldeseite anzeigen', 'auto_redirect' => 'Automatisch weiterleiten', 'windows_sso' => 'Windows SSO erzwingen', 'windows_sso_fallback' => 'Windows SSO, sonst Anmeldeseite', 'specific_provider' => 'Bestimmter externer Provider'] as $value => $label)
                            <option value="{{ $value }}" @selected($application->login_mode === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label value="Bevorzugter Provider" />
                    <x-input type="text" name="preferred_provider" value="{{ $application->preferred_provider }}" placeholder="z. B. entra-id, keycloak" />
                </div>
            </div>
            <div>
                <x-input-label value="Nach Daten fragen (Zustimmung)" />
                <x-select name="consent_mode">
                    @foreach (['first_time' => 'Nur beim ersten Mal fragen', 'always' => 'Immer fragen', 'on_scope_change' => 'Erneut fragen bei geänderten Berechtigungen', 'skip' => 'Nie fragen'] as $value => $label)
                        <option value="{{ $value }}" @selected($application->consent_mode === $value)>{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="space-y-2">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="consent_required" value="1" :checked="$application->consent_required" />
                    Zustimmungsseite anzeigen
                </label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="is_active" value="1" :checked="$application->is_active" />
                    Anwendung aktiv
                </label>
            </div>

            <div class="space-y-3 border-t border-gray-100 pt-4">
                <h4 class="text-sm font-semibold text-gray-900">Wartungsmodus (nur diese Anwendung)</h4>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="maintenance_mode" value="1" :checked="$application->maintenance_mode" />
                    In Wartung, Anmeldung an dieser Anwendung ist gesperrt
                </label>
                <div>
                    <x-input-label value="Wartungsmeldung" />
                    <x-textarea name="maintenance_message" rows="2" placeholder="Diese Anwendung wird zurzeit gewartet.">{{ $application->maintenance_message }}</x-textarea>
                </div>
                <div>
                    <x-input-label value="Wer trotzdem rein darf" />
                    <p class="mt-1 text-xs text-gray-500">Ein Eintrag pro Zeile: Benutzername oder <code>@Gruppenname</code>. Lokale Administratoren haben immer Zugriff.</p>
                    <x-textarea name="maintenance_allow" rows="3" placeholder="mmustermann&#10;@IT-Abteilung">{{ $application->maintenance_allow }}</x-textarea>
                </div>
            </div>

            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>

    @foreach ($application->oauthClients as $client)
        <x-card title="OAuth- / OIDC-Client" description="Diese Werte trägst du in der Anwendung ein.">
            <x-dl mono class="mb-4" :rows="[
                'Client ID' => $client->client_id,
                'Issuer' => config('app.url'),
                'Authorization Endpoint' => url('/oauth/authorize'),
                'Token Endpoint' => url('/oauth/token'),
                'UserInfo Endpoint' => url('/oauth/userinfo'),
                'Logout Endpoint' => url('/oauth/logout'),
                'Discovery' => url('/.well-known/openid-configuration'),
            ]" />

            <form method="POST" action="{{ route('admin.applications.clients.update', [$application, $client]) }}" class="space-y-3 border-t border-gray-100 pt-4">
                @csrf @method('PUT')
                <div>
                    <x-input-label value="Redirect URIs (eine pro Zeile)" />
                    <x-textarea name="redirect_uris" rows="2">{{ $client->redirectUris->where('type', 'login')->pluck('uri')->implode("\n") }}</x-textarea>
                    <p class="mt-1 text-xs text-gray-500">Adressen, zu denen nach dem Login weitergeleitet werden darf. Muss exakt passen.</p>
                </div>
                <div>
                    <x-input-label value="Logout Redirect URIs (optional, eine pro Zeile)" />
                    <x-textarea name="logout_redirect_uris" rows="2">{{ $client->redirectUris->where('type', 'logout')->pluck('uri')->implode("\n") }}</x-textarea>
                    <p class="mt-1 text-xs text-gray-500">Adressen, zu denen nach dem Abmelden zurückgeleitet werden darf.</p>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div><x-input-label value="Access Token (s)" /><x-input type="number" name="access_token_lifetime" value="{{ $client->access_token_lifetime }}" /></div>
                    <div><x-input-label value="Refresh Token (s)" /><x-input type="number" name="refresh_token_lifetime" value="{{ $client->refresh_token_lifetime }}" /></div>
                    <div><x-input-label value="ID Token (s)" /><x-input type="number" name="id_token_lifetime" value="{{ $client->id_token_lifetime }}" /></div>
                </div>
                <div>
                    <x-input-label value="Anmeldeverfahren (Grant Types)" />
                    <div class="mt-1 flex flex-wrap gap-4">
                        @foreach (['authorization_code' => 'Normaler Login', 'refresh_token' => 'Angemeldet bleiben', 'client_credentials' => 'Server zu Server'] as $grant => $label)
                            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                                <x-checkbox name="grant_types[]" value="{{ $grant }}" :checked="in_array($grant, $client->allowed_grant_types ?? [])" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="pkce_required" value="1" :checked="$client->pkce_required" /> PKCE erforderlich</label>
                    <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="secret_required" value="1" :checked="$client->secret_required" /> Client Secret erforderlich</label>
                    <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="is_active" value="1" :checked="$client->is_active" /> Client aktiv</label>
                </div>
                <x-button type="submit" size="sm">Speichern</x-button>
            </form>

            @if ($client->secret_required)
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <x-confirm-form :action="route('admin.applications.clients.regenerate-secret', [$application, $client])" method="POST" icon="key"
                                    title="Secret neu erzeugen"
                                    message="Ein neues Client Secret wird erzeugt. Das alte wird sofort ungültig und muss in der Anwendung aktualisiert werden."
                                    label="Secret neu erzeugen" variant="secondary" size="sm" />
                </div>
            @endif
        </x-card>
    @endforeach

    <x-card title="Zugriffsregeln"
            description="Ohne Regeln haben alle angemeldeten Benutzer Zugriff. Deny hat immer Vorrang vor Allow.">
        <x-table :heads="['Effekt', 'Typ', 'Wert', 'Priorität', '']" class="mb-4">
            <tbody class="divide-y divide-gray-100">
                @forelse ($application->accessPolicies as $policy)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2"><x-badge :color="$policy->effect === 'deny' ? 'red' : 'green'">{{ $policy->effect }}</x-badge></td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_type }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_value }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->priority }}</td>
                        <td class="px-3 py-2 text-right">
                            <x-confirm-form :action="route('admin.applications.policies.destroy', [$application, $policy])" message="Regel löschen?" label="Löschen" size="sm" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state cell :colspan="5" icon="users" title="Keine Regeln">
                        Alle angemeldeten Benutzer haben Zugriff.
                    </x-empty-state>
                @endforelse
            </tbody>
        </x-table>
        <form method="POST" action="{{ route('admin.applications.policies.store', $application) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <x-select name="effect" class="!w-40">
                <option value="allow">Allow</option>
                <option value="deny">Deny</option>
            </x-select>
            <x-select name="subject_type" class="!w-40">
                <option value="group">Gruppe</option>
                <option value="user">Benutzer</option>
                <option value="domain">Domain</option>
            </x-select>
            <x-input type="text" name="subject_value" placeholder="z. B. IT" required class="!w-48" />
            <x-input type="number" name="priority" placeholder="Priorität" value="0" class="!w-28" />
            <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
        </form>
    </x-card>

    <x-danger-zone>
        <p class="w-full text-sm text-red-700">Die Anwendung {{ $application->name }} und alle zugehörigen OAuth-Clients werden endgültig gelöscht.</p>
        <x-confirm-form :action="route('admin.applications.destroy', $application)"
                        title="Anwendung löschen"
                        :message="'Die Anwendung '.$application->name.' und alle zugehörigen OAuth-Clients werden endgültig gelöscht. Das lässt sich nicht rückgängig machen.'"
                        label="Anwendung löschen" size="sm" />
    </x-danger-zone>
</div>
@endsection
