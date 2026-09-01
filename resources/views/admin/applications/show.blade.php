@extends('layouts.admin')

@section('admin-content')
<div x-data="{ locked: true }">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $application->name }}</h1>
        <button type="button" @click="locked = !locked"
            :class="locked ? 'bg-gray-100 text-gray-600 border-gray-300' : 'bg-amber-50 text-amber-700 border-amber-300'"
            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium">
            <x-icon name="lock-closed" x-show="locked" class="h-4 w-4" />
            <x-icon name="lock-open" x-show="!locked" x-cloak class="h-4 w-4" />
            <span x-text="locked ? 'Schutzmodus aktiv' : 'Bearbeitung entsperrt'"></span>
        </button>
    </div>

    <p class="text-xs text-gray-500 mb-4" x-show="locked">Diese Seite ist schreibgeschützt. Klicke auf "Schutzmodus aktiv", um Änderungen vorzunehmen.</p>

    @if (session('plain_client_secret'))
        <x-alert type="warning">
            <strong>Client Secret (nur jetzt sichtbar):</strong>
            <code class="bg-white/50 px-1 rounded">{{ session('plain_client_secret') }}</code>
            <div class="text-xs mt-1">Bitte jetzt sicher speichern – nach dem Verlassen der Seite kann es nicht erneut angezeigt werden.</div>
        </x-alert>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="space-y-6">
            <x-card title="Anwendung">
                <fieldset :disabled="locked" class="space-y-4 disabled:opacity-60">
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
                        <p class="mt-1 text-xs text-gray-500">Wird normalen Benutzern im Dashboard als Kachel angezeigt, wenn sie Zugriff auf diese Anwendung haben.</p>
                    </div>
                    <div>
                        <x-input-label value="Bereich (optional)" />
                        <x-input type="text" name="category" list="category-suggestions" value="{{ $application->category }}" placeholder="z.B. Allgemein" />
                        <datalist id="category-suggestions">
                            @foreach ($categories as $category)
                                <option value="{{ $category }}">
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-xs text-gray-500">Fasst Anwendungen im Benutzer-Dashboard zu Bereichen zusammen. Gleicher Name wie bei einer bestehenden Anwendung gruppiert sie zusammen.</p>
                    </div>
                    <div>
                        <x-input-label value="Login-Verhalten" />
                        <x-select name="login_mode">
                            @foreach (['user_choice' => 'Login-Seite anzeigen', 'auto_redirect' => 'Automatische Weiterleitung', 'windows_sso' => 'Windows SSO erzwingen', 'windows_sso_fallback' => 'Windows SSO mit Fallback', 'specific_provider' => 'Bestimmter externer Provider'] as $value => $label)
                                <option value="{{ $value }}" {{ $application->login_mode === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <x-input-label value="Bevorzugter Provider" />
                        <x-input type="text" name="preferred_provider" value="{{ $application->preferred_provider }}" />
                    </div>
                    <div>
                        <x-input-label value="Consent-Modus" />
                        <x-select name="consent_mode">
                            @foreach (['first_time' => 'Nur beim ersten Mal', 'always' => 'Immer', 'on_scope_change' => 'Bei Scope-Änderung', 'skip' => 'Überspringen'] as $value => $label)
                                <option value="{{ $value }}" {{ $application->consent_mode === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                            <x-checkbox name="consent_required" value="1" :checked="$application->consent_required" />
                            Consent erforderlich
                        </label>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                            <x-checkbox name="is_active" value="1" :checked="$application->is_active" />
                            Aktiv
                        </label>
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-3">
                        <h4 class="text-sm font-semibold text-gray-900">Wartungsmodus (nur diese Anwendung)</h4>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                            <x-checkbox name="maintenance_mode" value="1" :checked="$application->maintenance_mode" />
                            In Wartung — Anmeldung an dieser Anwendung ist gesperrt
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

                <div class="pt-4 border-t border-gray-100">
                    <x-confirm-form :action="route('admin.applications.destroy', $application)" title="Anwendung löschen" message="Die Anwendung „{{ $application->name }}“ und alle zugehörigen OAuth-Clients werden endgültig gelöscht. Diese Aktion kann nicht rückgängig gemacht werden." label="Anwendung löschen" />
                </div>
                </fieldset>
            </x-card>

            <x-card title="Zugriffsregeln">
                <fieldset :disabled="locked" class="disabled:opacity-60">
                <x-table class="mb-4">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">Effekt</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">Typ</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">Wert</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">Priorität</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($application->accessPolicies as $policy)
                            <tr>
                                <td class="px-3 py-2"><x-badge :color="$policy->effect === 'deny' ? 'red' : 'green'">{{ $policy->effect }}</x-badge></td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->subject_type }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->subject_value }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->priority }}</td>
                                <td class="px-3 py-2">
                                    <x-confirm-form :action="route('admin.applications.policies.destroy', [$application, $policy])" message="Regel löschen?" label="Löschen" size="sm" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-2 text-gray-400">Keine Regeln — alle authentifizierten Benutzer haben Zugriff.</td></tr>
                        @endforelse
                    </tbody>
                </x-table>
                <form method="POST" action="{{ route('admin.applications.policies.store', $application) }}" class="flex flex-wrap gap-2 items-end">
                    @csrf
                    <x-select name="effect" class="!w-56">
                        <option value="allow">Allow</option>
                        <option value="deny">Deny</option>
                    </x-select>
                    <x-select name="subject_type" class="!w-56">
                        <option value="group">Gruppe</option>
                        <option value="user">Benutzer</option>
                        <option value="domain">Domain</option>
                    </x-select>
                    <x-input type="text" name="subject_value" placeholder="z.B. IT" required class="!w-56" />
                    <x-input type="number" name="priority" placeholder="Priorität" value="0" class="!w-28" />
                    <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
                </form>
                <p class="text-xs text-gray-500 mt-2">Deny hat immer Vorrang vor Allow. Ohne Regeln haben alle angemeldeten Benutzer Zugriff.</p>
                </fieldset>
            </x-card>
        </div>

        <div class="space-y-6">
            @foreach ($application->oauthClients as $client)
                <x-card title="OAuth/OIDC Client">
                    <x-dl class="mb-4" :rows="[
                        'Client ID' => $client->client_id,
                        'Issuer' => config('app.url'),
                        'Authorization Endpoint' => url('/oauth/authorize'),
                        'Token Endpoint' => url('/oauth/token'),
                        'UserInfo Endpoint' => url('/oauth/userinfo'),
                        'Discovery' => url('/.well-known/openid-configuration'),
                    ]" />

                    <fieldset :disabled="locked" class="disabled:opacity-60">
                    <form method="POST" action="{{ route('admin.applications.clients.update', [$application, $client]) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div>
                            <x-input-label value="Redirect URIs" />
                            <x-textarea name="redirect_uris" rows="2">{{ $client->redirectUris->where('type', 'login')->pluck('uri')->implode("\n") }}</x-textarea>
                        </div>
                        <div>
                            <x-input-label value="Logout Redirect URIs" />
                            <x-textarea name="logout_redirect_uris" rows="2">{{ $client->redirectUris->where('type', 'logout')->pluck('uri')->implode("\n") }}</x-textarea>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div><x-input-label value="Access Token" /><x-input type="number" name="access_token_lifetime" value="{{ $client->access_token_lifetime }}" /></div>
                            <div><x-input-label value="Refresh Token" /><x-input type="number" name="refresh_token_lifetime" value="{{ $client->refresh_token_lifetime }}" /></div>
                            <div><x-input-label value="ID Token" /><x-input type="number" name="id_token_lifetime" value="{{ $client->id_token_lifetime }}" /></div>
                        </div>
                        <div class="flex flex-wrap gap-4">
                            @foreach (['authorization_code', 'refresh_token', 'client_credentials'] as $grant)
                                <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                                    <x-checkbox name="grant_types[]" value="{{ $grant }}" :checked="in_array($grant, $client->allowed_grant_types ?? [])" />
                                    {{ $grant }}
                                </label>
                            @endforeach
                        </div>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="pkce_required" value="1" :checked="$client->pkce_required" /> PKCE erforderlich</label>
                            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="secret_required" value="1" :checked="$client->secret_required" /> Secret erforderlich</label>
                            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="is_active" value="1" :checked="$client->is_active" /> Aktiv</label>
                        </div>
                        <x-button type="submit" size="sm">Speichern</x-button>
                    </form>

                    @if ($client->secret_required)
                        <x-confirm-form :action="route('admin.applications.clients.regenerate-secret', [$application, $client])" method="POST" icon="key" title="Secret neu erzeugen" message="Ein neues Client Secret wird erzeugt. Das alte wird sofort ungültig und muss in der Client-Anwendung aktualisiert werden." label="Secret neu erzeugen" variant="secondary" size="sm" class="mt-3" />
                    @endif
                    </fieldset>
                </x-card>
            @endforeach
        </div>
    </div>
</div>
@endsection
