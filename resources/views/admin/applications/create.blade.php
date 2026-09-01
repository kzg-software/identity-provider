@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-1">Anwendung anlegen</h1>
<p class="text-sm text-gray-500 mb-6">Verbindet eine externe Anwendung per OAuth 2.0 / OpenID Connect mit diesem System.</p>

<form method="POST" action="{{ route('admin.applications.store') }}" x-data="{ advanced: false }" class="space-y-6">
    @csrf

    <x-card title="1. Grunddaten">
        <div class="space-y-4">
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ old('name') }}" required autofocus />
            </div>
            <div>
                <x-input-label value="Beschreibung (optional)" />
                <x-textarea name="description">{{ old('description') }}</x-textarea>
            </div>
            <div>
                <x-input-label value="Start-URL (optional)" />
                <x-input type="url" name="launch_url" value="{{ old('launch_url') }}" placeholder="https://app.example.de" />
                <p class="mt-1 text-xs text-gray-500">Erscheint normalen Benutzern im Dashboard als Kachel, wenn sie Zugriff auf diese Anwendung haben.</p>
            </div>
            <div>
                <x-input-label value="Bereich (optional)" />
                <x-input type="text" name="category" list="category-suggestions" value="{{ old('category') }}" placeholder="z.B. Allgemein" />
                <datalist id="category-suggestions">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">
                    @endforeach
                </datalist>
                <p class="mt-1 text-xs text-gray-500">Fasst Anwendungen im Benutzer-Dashboard zu Bereichen zusammen (z.B. "Allgemein"). Gleicher Name wie bei einer bestehenden Anwendung gruppiert sie zusammen; leer lassen für keinen Bereich.</p>
            </div>
        </div>
    </x-card>

    <x-card title="2. Wohin darf die Anwendung zurückleiten?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Redirect URIs (eine pro Zeile)" />
                <x-textarea name="redirect_uris" rows="3" required placeholder="https://app.example.de/auth/callback">{{ old('redirect_uris') }}</x-textarea>
                <p class="mt-1 text-xs text-gray-500">Die Adresse(n), zu denen nach erfolgreichem Login weitergeleitet werden darf. Muss exakt mit dem übereinstimmen, was die Anwendung sendet.</p>
            </div>
            <div>
                <x-input-label value="Logout Redirect URIs (optional, eine pro Zeile)" />
                <x-textarea name="logout_redirect_uris" rows="2">{{ old('logout_redirect_uris') }}</x-textarea>
            </div>
        </div>
    </x-card>

    <x-card title="3. Wie melden sich Benutzer an?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Login-Verhalten" />
                <x-select name="login_mode">
                    <option value="user_choice">Login-Seite anzeigen (Benutzer wählt selbst)</option>
                    <option value="auto_redirect">Automatisch weiterleiten</option>
                    <option value="windows_sso">Windows SSO erzwingen</option>
                    <option value="windows_sso_fallback">Windows SSO, sonst Login-Seite</option>
                    <option value="specific_provider">Bestimmter externer Identity Provider</option>
                </x-select>
            </div>
            <div>
                <x-input-label value="Consent-Modus" />
                <x-select name="consent_mode">
                    <option value="first_time">Nur beim ersten Mal fragen</option>
                    <option value="always">Immer fragen</option>
                    <option value="on_scope_change">Erneut fragen bei geänderten Berechtigungen</option>
                    <option value="skip">Nie fragen (Consent überspringen)</option>
                </x-select>
            </div>
            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                <x-checkbox name="consent_required" value="1" checked />
                Benutzer müssen dem Datenzugriff zustimmen (Consent-Seite anzeigen)
            </label>
        </div>
    </x-card>

    <x-card title="4. Welche Daten darf die Anwendung sehen?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Freigegebene Daten (Scopes)" />
                <div class="flex flex-wrap gap-4 mt-1">
                    @foreach ($scopes as $scope)
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                            <x-checkbox name="scopes[]" value="{{ $scope->key }}" :checked="$scope->is_default" />
                            {{ $scope->label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <x-input-label value="Anmeldeverfahren (Grant Types)" />
                <div class="flex flex-wrap gap-4 mt-1">
                    @foreach (['authorization_code' => 'Normaler Login (Authorization Code + PKCE)', 'refresh_token' => 'Angemeldet bleiben (Refresh Token)', 'client_credentials' => 'Server-zu-Server ohne Benutzer (Client Credentials)'] as $value => $label)
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                            <x-checkbox name="grant_types[]" value="{{ $value }}" :checked="$value !== 'client_credentials'" />
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </x-card>

    <x-card>
        <button type="button" @click="advanced = !advanced" class="flex items-center gap-2 text-sm font-medium text-gray-700">
            <svg class="h-4 w-4 transition-transform" :class="advanced && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
            Erweiterte Einstellungen (Token-Laufzeiten, Sicherheit)
        </button>

        <div x-show="advanced" x-cloak class="mt-4 space-y-4 pt-4 border-t border-gray-100">
            <div>
                <x-input-label value='Bestimmter externer Provider (nur bei Login-Verhalten "bestimmter Provider")' />
                <x-input type="text" name="preferred_provider" value="{{ old('preferred_provider') }}" placeholder="z.B. entra-id, keycloak" />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <x-input-label value="Access Token gültig für (s)" />
                    <x-input type="number" name="access_token_lifetime" value="3600" required />
                </div>
                <div>
                    <x-input-label value="Refresh Token gültig für (s)" />
                    <x-input type="number" name="refresh_token_lifetime" value="1209600" required />
                </div>
                <div>
                    <x-input-label value="ID Token gültig für (s)" />
                    <x-input type="number" name="id_token_lifetime" value="3600" required />
                </div>
            </div>

            <div class="space-y-2">
                <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                    <x-checkbox name="pkce_required" value="1" checked />
                    PKCE erforderlich <span class="text-gray-400">(empfohlen, zusätzlicher Schutz gegen Code-Diebstahl)</span>
                </label>
                <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
                    <x-checkbox name="secret_required" value="1" checked />
                    Client Secret erforderlich <span class="text-gray-400">(deaktivieren nur für reine Browser-/Mobile-Apps ohne Backend)</span>
                </label>
            </div>
        </div>
    </x-card>

    <div class="flex gap-3">
        <x-button type="submit">Anwendung anlegen</x-button>
        <x-button tag="a" href="{{ route('admin.applications.index') }}" variant="link">Abbrechen</x-button>
    </div>
</form>
@endsection
