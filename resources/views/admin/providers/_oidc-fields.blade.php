{{--
    OAuth-2.0- / OIDC-Proverfelder. Wird vom Anwendungs-Assistenten und von der
    Provider-Erstellung eingebunden.
    Erwartet: $scopes (Collection<OauthScope>)
    Optional:  $client (OauthClient) für Vorbelegung beim Bearbeiten
--}}
@php
    $client = $client ?? null;
    $required = $required ?? true;
@endphp

<div class="space-y-5">
    <div>
        <x-input-label>Redirect URIs (eine pro Zeile)
            <x-field-info :required="true" example="https://app.example.de/auth/callback">
                URL, zu der der Benutzer nach erfolgreicher Anmeldung zurückgeleitet wird.
                Muss exakt mit dem übereinstimmen, was die Anwendung sendet.
            </x-field-info>
        </x-input-label>
        <x-textarea name="redirect_uris" rows="3" :required="$required" placeholder="https://app.example.de/auth/callback">{{ old('redirect_uris', $client?->redirectUris->where('type', 'login')->pluck('uri')->implode("\n")) }}</x-textarea>
    </div>

    <div>
        <x-input-label>Freigegebene Daten (Scopes)
            <x-field-info>
                Legt fest, welche Benutzerdaten die Anwendung erhalten darf. Nicht
                ausgewählte Daten werden weggelassen, auch wenn die Anwendung sie anfragt.
                „OpenID“ ist immer aktiv.
            </x-field-info>
        </x-input-label>
        <div class="mt-1 flex flex-wrap gap-4">
            @foreach ($scopes as $scope)
                @php
                    $checked = $client
                        ? ($client->allowed_scopes === null || in_array($scope->key, $client->allowed_scopes))
                        : (old('scopes') !== null ? in_array($scope->key, old('scopes', [])) : $scope->is_default);
                @endphp
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="scopes[]" value="{{ $scope->key }}" :checked="$checked" />
                    {{ $scope->label }}
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <x-input-label>Anmeldeverfahren (Grant Types)
            <x-field-info>Wie die Anwendung Tokens anfordert. „Normaler Login“ passt für fast alle Fälle.</x-field-info>
        </x-input-label>
        <div class="mt-1 flex flex-wrap gap-4">
            @foreach (['authorization_code' => 'Normaler Login (Authorization Code + PKCE)', 'refresh_token' => 'Angemeldet bleiben (Refresh Token)', 'client_credentials' => 'Server zu Server ohne Benutzer'] as $value => $label)
                @php
                    $checked = $client
                        ? in_array($value, $client->allowed_grant_types ?? [])
                        : (old('grant_types') !== null ? in_array($value, old('grant_types', [])) : $value !== 'client_credentials');
                @endphp
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="grant_types[]" value="{{ $value }}" :checked="$checked" />
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
        <input type="hidden" name="_has_pkce_required" value="1">
        <x-checkbox name="pkce_required" value="1" :checked="$client ? $client->pkce_required : old('pkce_required', true)" />
        PKCE erforderlich
        <x-field-info>
            Zusätzlicher Schutz für OAuth-Anmeldungen. Für öffentliche Clients (SPA,
            Mobile-App ohne Backend) dringend empfohlen.
        </x-field-info>
    </label>
</div>

<details class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4" @if($errors->hasAny(['access_token_lifetime','refresh_token_lifetime','id_token_lifetime'])) open @endif>
    <summary class="cursor-pointer text-sm font-medium text-gray-700">Erweiterte Einstellungen</summary>
    <div class="mt-4 space-y-5">
        <div>
            <x-input-label>Logout Redirect URIs (optional, eine pro Zeile)
                <x-field-info>Adressen, zu denen nach dem Abmelden zurückgeleitet werden darf.</x-field-info>
            </x-input-label>
            <x-textarea name="logout_redirect_uris" rows="2">{{ old('logout_redirect_uris', $client?->redirectUris->where('type', 'logout')->pluck('uri')->implode("\n")) }}</x-textarea>
        </div>

        <div>
            <x-input-label>Response Types</x-input-label>
            <div class="mt-1 flex flex-wrap gap-4">
                @foreach (['code' => 'code', 'id_token' => 'id_token', 'token' => 'token'] as $value => $label)
                    @php
                        $checked = $client
                            ? in_array($value, $client->allowed_response_types ?? ['code'])
                            : (old('response_types') !== null ? in_array($value, old('response_types', [])) : $value === 'code');
                    @endphp
                    <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                        <x-checkbox name="response_types[]" value="{{ $value }}" :checked="$checked" />
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-input-label>Access Token (s)</x-input-label>
                <x-input type="number" name="access_token_lifetime" value="{{ old('access_token_lifetime', $client?->access_token_lifetime ?? 3600) }}" :required="$required" />
            </div>
            <div>
                <x-input-label>Refresh Token (s)</x-input-label>
                <x-input type="number" name="refresh_token_lifetime" value="{{ old('refresh_token_lifetime', $client?->refresh_token_lifetime ?? 1209600) }}" :required="$required" />
            </div>
            <div>
                <x-input-label>ID Token (s)</x-input-label>
                <x-input type="number" name="id_token_lifetime" value="{{ old('id_token_lifetime', $client?->id_token_lifetime ?? 3600) }}" :required="$required" />
            </div>
        </div>

        <div>
            <x-input-label>Signaturalgorithmus (ID Token)
                <x-field-info>Mit welchem Verfahren das ID Token signiert wird. RS256 ist der Standard.</x-field-info>
            </x-input-label>
            <x-select name="id_token_signed_response_alg">
                @foreach (['RS256', 'RS384', 'RS512', 'ES256'] as $alg)
                    <option value="{{ $alg }}" @selected(old('id_token_signed_response_alg', $client?->id_token_signed_response_alg ?? 'RS256') === $alg)>{{ $alg }}</option>
                @endforeach
            </x-select>
        </div>

        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
            <input type="hidden" name="_has_secret_required" value="1">
            <x-checkbox name="secret_required" value="1" :checked="$client ? $client->secret_required : old('secret_required', true)" />
            Client Secret erforderlich
            <x-field-info>
                Geheimer Schlüssel zur Authentifizierung der Anwendung. Nicht öffentlich
                weitergeben; nur direkt nach dem Erzeugen sichtbar. Für reine Browser-/
                Mobile-Apps ohne Backend deaktivieren.
            </x-field-info>
        </label>
    </div>
</details>
