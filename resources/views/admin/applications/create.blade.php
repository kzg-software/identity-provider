@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Anwendung anlegen"
    :back="route('admin.applications.index')" back-label="Alle Anwendungen"
    description="Verbindet ein Programm per OAuth 2.0 oder OpenID Connect mit diesem System. Nach dem Speichern bekommst du Client ID und Secret." />

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
                <p class="mt-1 text-xs text-gray-500">Erscheint Benutzern im Portal als Kachel, wenn sie Zugriff auf diese Anwendung haben.</p>
            </div>
            <div>
                <x-input-label value="Bereich (optional)" />
                <x-input type="text" name="category" list="category-suggestions" value="{{ old('category') }}" placeholder="z. B. Allgemein" />
                <datalist id="category-suggestions">
                    @foreach ($categories as $category)
                        <option value="{{ $category }}">
                    @endforeach
                </datalist>
                <p class="mt-1 text-xs text-gray-500">Fasst Anwendungen im Portal zu Bereichen zusammen. Gleicher Name gruppiert sie zusammen, leer lassen für keinen Bereich.</p>
            </div>
        </div>
    </x-card>

    <x-card title="2. Wohin darf die Anwendung zurückleiten?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Redirect URIs (eine pro Zeile)" />
                <x-textarea name="redirect_uris" rows="3" required placeholder="https://app.example.de/auth/callback">{{ old('redirect_uris') }}</x-textarea>
                <p class="mt-1 text-xs text-gray-500">Adressen, zu denen nach dem Login weitergeleitet werden darf. Muss exakt mit dem übereinstimmen, was die Anwendung sendet.</p>
            </div>
            <div>
                <x-input-label value="Logout Redirect URIs (optional, eine pro Zeile)" />
                <x-textarea name="logout_redirect_uris" rows="2">{{ old('logout_redirect_uris') }}</x-textarea>
                <p class="mt-1 text-xs text-gray-500">Adressen, zu denen nach dem Abmelden zurückgeleitet werden darf.</p>
            </div>
        </div>
    </x-card>

    <x-card title="3. Wie melden sich Benutzer an?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Anmeldung" />
                <x-select name="login_mode">
                    <option value="user_choice">Anmeldeseite anzeigen (Benutzer wählt selbst)</option>
                    <option value="auto_redirect">Automatisch weiterleiten</option>
                    <option value="windows_sso">Windows SSO erzwingen</option>
                    <option value="windows_sso_fallback">Windows SSO, sonst Anmeldeseite</option>
                    <option value="specific_provider">Bestimmter externer Identity Provider</option>
                </x-select>
            </div>
            <div>
                <x-input-label value="Nach Daten fragen (Zustimmung)" />
                <x-select name="consent_mode">
                    <option value="first_time">Nur beim ersten Mal fragen</option>
                    <option value="always">Immer fragen</option>
                    <option value="on_scope_change">Erneut fragen bei geänderten Berechtigungen</option>
                    <option value="skip">Nie fragen</option>
                </x-select>
            </div>
            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                <x-checkbox name="consent_required" value="1" checked />
                Zustimmungsseite anzeigen
            </label>
        </div>
    </x-card>

    <x-card title="4. Welche Daten darf die Anwendung sehen?">
        <div class="space-y-4">
            <div>
                <x-input-label value="Freigegebene Daten (Scopes)" />
                <div class="mt-1 flex flex-wrap gap-4">
                    @foreach ($scopes as $scope)
                        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                            <x-checkbox name="scopes[]" value="{{ $scope->key }}" :checked="$scope->is_default" />
                            {{ $scope->label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <x-input-label value="Anmeldeverfahren (Grant Types)" />
                <div class="mt-1 flex flex-wrap gap-4">
                    @foreach (['authorization_code' => 'Normaler Login (Authorization Code + PKCE)', 'refresh_token' => 'Angemeldet bleiben (Refresh Token)', 'client_credentials' => 'Server zu Server ohne Benutzer (Client Credentials)'] as $value => $label)
                        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                            <x-checkbox name="grant_types[]" value="{{ $value }}" :checked="$value !== 'client_credentials'" />
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </x-card>

    <x-card>
        <button type="button" @click="advanced = ! advanced" class="flex items-center gap-2 text-sm font-medium text-gray-700">
            <svg class="h-4 w-4 transition-transform" :class="advanced && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
            Erweiterte Einstellungen (Token-Laufzeiten, Sicherheit)
        </button>

        <div x-show="advanced" x-cloak class="mt-4 space-y-4 border-t border-gray-100 pt-4">
            <div>
                <x-input-label value="Bestimmter externer Provider" />
                <x-input type="text" name="preferred_provider" value="{{ old('preferred_provider') }}" placeholder="z. B. entra-id, keycloak" />
                <p class="mt-1 text-xs text-gray-500">Nur relevant, wenn oben „Bestimmter externer Identity Provider" gewählt ist.</p>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <x-input-label value="Access Token (s)" />
                    <x-input type="number" name="access_token_lifetime" value="3600" required />
                </div>
                <div>
                    <x-input-label value="Refresh Token (s)" />
                    <x-input type="number" name="refresh_token_lifetime" value="1209600" required />
                </div>
                <div>
                    <x-input-label value="ID Token (s)" />
                    <x-input type="number" name="id_token_lifetime" value="3600" required />
                </div>
            </div>

            <div class="space-y-2">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="pkce_required" value="1" checked />
                    PKCE erforderlich <span class="text-gray-400">(empfohlen, zusätzlicher Schutz gegen Code-Diebstahl)</span>
                </label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="secret_required" value="1" checked />
                    Client Secret erforderlich <span class="text-gray-400">(deaktivieren nur für reine Browser- oder Mobile-Apps ohne Backend)</span>
                </label>
            </div>
        </div>
    </x-card>

    <div class="sticky bottom-0 -mx-4 flex gap-3 border-t border-gray-200 bg-gray-100 px-4 py-3 sm:mx-0 sm:rounded-lg sm:border sm:bg-white sm:px-4">
        <x-button type="submit">Anwendung anlegen</x-button>
        <x-button tag="a" href="{{ route('admin.applications.index') }}" variant="link">Abbrechen</x-button>
    </div>
</form>
@endsection
