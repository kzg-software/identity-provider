@extends('layouts.admin')

@section('admin-content')
@php
    $isOidc = $provider->isOidc();
    $client = $provider->oauthClient;
    $sp = $provider->samlServiceProvider;
    $tabs = $isOidc
        ? ['allgemein' => 'Allgemein', 'protokoll' => 'Protokoll', 'claims' => 'Claims', 'sicherheit' => 'Sicherheit', 'token' => 'Token & Session', 'erweitert' => 'Erweitert']
        : ['allgemein' => 'Allgemein', 'protokoll' => 'Protokoll', 'claims' => 'Attribute', 'sicherheit' => 'Sicherheit', 'token' => 'Session', 'erweitert' => 'Erweitert'];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'allgemein';
    $act = route('admin.providers.update', $provider);
@endphp

<x-page-header :title="$provider->name" :back="route('admin.providers.index')" back-label="Alle Provider">
    <x-slot:actions>
        <x-provider-badge :provider="$provider" />
        @if ($provider->is_active)<x-badge color="green">aktiv</x-badge>@else<x-badge>inaktiv</x-badge>@endif
    </x-slot:actions>
</x-page-header>

@if (session('plain_client_secret'))
    <x-alert type="warning">
        <strong>Client Secret (nur jetzt sichtbar):</strong>
        <code class="rounded bg-white/50 px-1">{{ session('plain_client_secret') }}</code>
        <div class="mt-1 text-xs">Jetzt sicher speichern – danach nicht mehr abrufbar.</div>
    </x-alert>
@endif

@if ($provider->applications->isNotEmpty())
    <p class="mb-4 text-sm text-gray-500">
        Verwendet von:
        @foreach ($provider->applications as $app)
            <a href="{{ route('admin.applications.show', $app) }}" class="text-laravel-600 hover:text-laravel-700">{{ $app->name }}</a>@if (! $loop->last), @endif
        @endforeach
    </p>
@else
    <x-alert type="info">Dieser Provider ist noch keiner Anwendung zugeordnet.</x-alert>
@endif

<x-tabs :base="route('admin.providers.show', $provider)" :current="$tab" :tabs="$tabs" />

{{-- ============ ALLGEMEIN ============ --}}
@if ($tab === 'allgemein')
    <x-card title="Allgemein">
        <form method="POST" action="{{ $act }}" class="space-y-4">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="allgemein">
            <input type="hidden" name="_has_is_active" value="1">
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ $provider->name }}" required />
            </div>
            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                <x-checkbox name="is_active" value="1" :checked="$provider->is_active" />
                Provider aktiv
                <x-field-info>Deaktivierte Provider lehnen alle Anmeldungen ab.</x-field-info>
            </label>

            @if ($isOidc)
                <x-dl mono class="border-t border-gray-100 pt-3" :rows="[
                    'Client ID' => $client->client_id,
                    'Client Secret' => $client->secret_required ? '••••••••  (nur einmalig nach dem Erzeugen sichtbar)' : 'nicht erforderlich (öffentlicher Client)',
                ]" />
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <input type="hidden" name="_has_secret_required" value="1">
                    <x-checkbox name="secret_required" value="1" :checked="$client->secret_required" />
                    Client Secret erforderlich
                    <x-field-info>Für reine Browser-/Mobile-Apps ohne Backend deaktivieren.</x-field-info>
                </label>
            @else
                <div class="border-t border-gray-100 pt-3">
                    <x-input-label>Entity ID (SP)
                        <x-field-info :required="true">Muss exakt dem &lt;saml:Issuer&gt; entsprechen, den die Anwendung sendet.</x-field-info>
                    </x-input-label>
                    <x-input type="text" name="entity_id" value="{{ $sp->entity_id }}" required />
                </div>
            @endif

            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>

    @if ($isOidc && $client->secret_required)
        <x-danger-zone class="mt-6" title="Client Secret neu erzeugen">
            <p class="w-full text-sm text-red-700">Das alte Secret wird sofort ungültig und muss in der Anwendung aktualisiert werden.</p>
            <x-confirm-form :action="route('admin.providers.regenerate-secret', $provider)" method="POST" icon="key"
                            title="Secret neu erzeugen" message="Ein neues Client Secret wird erzeugt. Das alte wird sofort ungültig."
                            label="Secret neu erzeugen" variant="secondary" size="sm" />
        </x-danger-zone>
    @endif
@endif

{{-- ============ PROTOKOLL ============ --}}
@if ($tab === 'protokoll')
    <x-card title="Protokoll">
        <form method="POST" action="{{ $act }}" class="space-y-5">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="protokoll">
            @if ($isOidc)
                <input type="hidden" name="_has_pkce_required" value="1">
                <input type="hidden" name="_has_grant_types" value="1">
                <input type="hidden" name="_has_response_types" value="1">
                <div>
                    <x-input-label>Redirect URIs (eine pro Zeile)
                        <x-field-info :required="true" example="https://app.example.de/auth/callback">
                            URL, zu der der Benutzer nach erfolgreicher Anmeldung zurückgeleitet wird. Muss exakt passen.
                        </x-field-info>
                    </x-input-label>
                    <x-textarea name="redirect_uris" rows="3" required>{{ $client->redirectUris->where('type', 'login')->pluck('uri')->implode("\n") }}</x-textarea>
                </div>
                <div>
                    <x-input-label>Logout Redirect URIs (optional, eine pro Zeile)
                        <x-field-info>Adressen, zu denen nach dem Abmelden zurückgeleitet werden darf.</x-field-info>
                    </x-input-label>
                    <x-textarea name="logout_redirect_uris" rows="2">{{ $client->redirectUris->where('type', 'logout')->pluck('uri')->implode("\n") }}</x-textarea>
                </div>
                <div>
                    <x-input-label>Grant Types</x-input-label>
                    <div class="mt-1 flex flex-wrap gap-4">
                        @foreach (['authorization_code' => 'Normaler Login', 'refresh_token' => 'Angemeldet bleiben', 'client_credentials' => 'Server zu Server'] as $g => $label)
                            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                                <x-checkbox name="grant_types[]" value="{{ $g }}" :checked="in_array($g, $client->allowed_grant_types ?? [])" />{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <x-input-label>Response Types</x-input-label>
                    <div class="mt-1 flex flex-wrap gap-4">
                        @foreach (['code', 'id_token', 'token'] as $rt)
                            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                                <x-checkbox name="response_types[]" value="{{ $rt }}" :checked="in_array($rt, $client->allowed_response_types ?? ['code'])" />{{ $rt }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="pkce_required" value="1" :checked="$client->pkce_required" />
                    PKCE erforderlich
                    <x-field-info>Zusätzlicher Schutz für OAuth-Anmeldungen. Für öffentliche Clients dringend empfohlen.</x-field-info>
                </label>
            @else
                <div>
                    <x-input-label>ACS URL
                        <x-field-info :required="true">Hierhin schickt das System die SAML-Antwort nach dem Login.</x-field-info>
                    </x-input-label>
                    <x-input type="url" name="acs_url" value="{{ $sp->acs_url }}" required />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label value="Single Logout URL (optional)" />
                        <x-input type="url" name="slo_url" value="{{ $sp->slo_url }}" />
                    </div>
                    <div>
                        <x-input-label value="SLO-Binding" />
                        <x-select name="slo_binding">
                            @foreach (['urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect' => 'HTTP-Redirect', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST' => 'HTTP-POST'] as $v => $l)
                                <option value="{{ $v }}" @selected($sp->slo_binding === $v)>{{ $l }}</option>
                            @endforeach
                        </x-select>
                    </div>
                </div>
                <div>
                    <x-input-label>NameID-Format
                        <x-field-info>Format der Benutzerkennung. „persistent“ ist die datenschutzfreundliche Voreinstellung.</x-field-info>
                    </x-input-label>
                    <x-select name="name_id_format">
                        @foreach ($nameIdFormats as $v => $l)
                            <option value="{{ $v }}" @selected($sp->name_id_format === $v)>{{ $l }}</option>
                        @endforeach
                    </x-select>
                </div>
            @endif
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>
@endif

{{-- ============ CLAIMS / ATTRIBUTE ============ --}}
@if ($tab === 'claims')
    @if ($isOidc)
        <x-card title="Freigegebene Daten (Scopes)"
                description="Nicht ausgewählte Daten werden bei der Anmeldung weggelassen, auch wenn die Anwendung sie anfragt. „OpenID“ ist immer aktiv.">
            <form method="POST" action="{{ $act }}" class="space-y-4">
                @csrf @method('PUT')
                <input type="hidden" name="section" value="claims">
                <input type="hidden" name="_has_scopes" value="1">
                <div class="flex flex-wrap gap-4">
                    @foreach ($scopes as $scope)
                        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                            <x-checkbox name="scopes[]" value="{{ $scope->key }}"
                                        :checked="$client->allowed_scopes === null || in_array($scope->key, $client->allowed_scopes)" />
                            {{ $scope->label }}
                        </label>
                    @endforeach
                </div>
                <div class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                    <p class="font-medium text-gray-700">Welche Claims je Scope ausgeliefert werden:</p>
                    <ul class="mt-1 space-y-0.5">
                        <li><code>openid</code> → <code>sub</code></li>
                        <li><code>profile</code> → name, given_name, family_name, preferred_username, department, company</li>
                        <li><code>email</code> → email, email_verified</li>
                        <li><code>groups</code> → groups, roles</li>
                    </ul>
                </div>
                <x-button type="submit" size="sm">Speichern</x-button>
            </form>
        </x-card>
    @else
        <x-card title="Attribut-Mapping"
                description="Legt fest, welches Benutzerattribut unter welchem SAML-Attributnamen übertragen wird.">
            <x-table :heads="['SAML-Attribut', 'Benutzerattribut', '']" class="mb-4">
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sp->attributeMappings as $mapping)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $mapping->saml_attribute }}</code></td>
                            <td class="px-3 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $mapping->user_attribute }}</code></td>
                            <td class="px-3 py-2 text-right">
                                <x-confirm-form :action="route('admin.providers.mappings.destroy', [$provider, $mapping])" message="Mapping entfernen?" label="Entfernen" size="sm" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-state cell :colspan="3" icon="signpost" title="Kein Mapping">Ohne Mapping werden keine zusätzlichen Attribute übertragen.</x-empty-state>
                    @endforelse
                </tbody>
            </x-table>
            <form method="POST" action="{{ route('admin.providers.mappings.store', $provider) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <x-input type="text" name="saml_attribute" placeholder="SAML-Attribut" required class="!w-56" />
                <x-input type="text" name="user_attribute" placeholder="z. B. email, groups" required class="!w-56" />
                <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
            </form>
        </x-card>
    @endif
@endif

{{-- ============ SICHERHEIT ============ --}}
@if ($tab === 'sicherheit')
    <x-card title="Sicherheit">
        <form method="POST" action="{{ $act }}" class="space-y-5">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="sicherheit">
            @if ($isOidc)
                <div>
                    <x-input-label>Signaturalgorithmus (ID Token)
                        <x-field-info>Mit welchem Verfahren das ID Token signiert wird. RS256 ist der Standard.</x-field-info>
                    </x-input-label>
                    <x-select name="id_token_signed_response_alg">
                        @foreach (['RS256', 'RS384', 'RS512', 'ES256'] as $alg)
                            <option value="{{ $alg }}" @selected($client->id_token_signed_response_alg === $alg)>{{ $alg }}</option>
                        @endforeach
                    </x-select>
                </div>
            @else
                <div>
                    <x-input-label value="Signaturalgorithmus" />
                    <x-select name="sign_algorithm">
                        @foreach (['sha256', 'sha384', 'sha512', 'sha1'] as $alg)
                            <option value="{{ $alg }}" @selected($sp->sign_algorithm === $alg)>{{ strtoupper($alg) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="space-y-2">
                    @foreach ([
                        'sign_assertions' => 'Assertions signieren',
                        'sign_responses' => 'Responses signieren',
                        'encrypt_assertions' => 'Assertions verschlüsseln',
                        'want_name_id_encrypted' => 'NameID verschlüsseln',
                        'require_signed_requests' => 'Signierte AuthnRequests erforderlich',
                    ] as $flag => $label)
                        <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                            <input type="hidden" name="_has_{{ $flag }}" value="1">
                            <x-checkbox name="{{ $flag }}" value="1" :checked="$sp->{$flag}" />{{ $label }}
                        </label>
                    @endforeach
                </div>
                <div>
                    <x-input-label>SP-Zertifikat (PEM, optional)
                        <x-field-info>Nötig, wenn signierte AuthnRequests geprüft oder Assertions verschlüsselt werden sollen.</x-field-info>
                    </x-input-label>
                    <x-textarea name="certificate" rows="4" class="font-mono text-xs">{{ $sp->certificate }}</x-textarea>
                </div>
            @endif
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>
@endif

{{-- ============ TOKEN & SESSION ============ --}}
@if ($tab === 'token')
    <x-card :title="$isOidc ? 'Token & Session' : 'Session'">
        <form method="POST" action="{{ $act }}" class="space-y-5">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="token">
            @if ($isOidc)
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label>Access Token (s)<x-field-info>Gültigkeitsdauer des Access Tokens.</x-field-info></x-input-label>
                        <x-input type="number" name="access_token_lifetime" value="{{ $client->access_token_lifetime }}" required />
                    </div>
                    <div>
                        <x-input-label>Refresh Token (s)</x-input-label>
                        <x-input type="number" name="refresh_token_lifetime" value="{{ $client->refresh_token_lifetime }}" required />
                    </div>
                    <div>
                        <x-input-label>ID Token (s)</x-input-label>
                        <x-input type="number" name="id_token_lifetime" value="{{ $client->id_token_lifetime }}" required />
                    </div>
                </div>
            @else
                <div>
                    <x-input-label>Session-Laufzeit (Minuten, optional)
                        <x-field-info>SessionNotOnOrAfter in der Assertion. Leer = 8 Stunden.</x-field-info>
                    </x-input-label>
                    <x-input type="number" name="session_lifetime_minutes" value="{{ $sp->session_lifetime_minutes }}" class="sm:!w-48" />
                </div>
            @endif
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>
@endif

{{-- ============ ERWEITERT ============ --}}
@if ($tab === 'erweitert')
    <div class="space-y-6">
        <x-card title="Metadaten & Endpunkte" description="Diese Werte trägst du in der Anwendung ein.">
            @if ($isOidc)
                <x-dl mono :rows="[
                    'Issuer' => config('app.url'),
                    'Authorization Endpoint' => url('/oauth/authorize'),
                    'Token Endpoint' => url('/oauth/token'),
                    'UserInfo Endpoint' => url('/oauth/userinfo'),
                    'Logout Endpoint' => url('/oauth/logout'),
                    'Discovery' => url('/.well-known/openid-configuration'),
                    'JWKS' => url('/.well-known/jwks.json'),
                ]" />
            @else
                <x-dl mono :rows="array_filter([
                    'Entity ID (IdP)' => url('/saml/metadata'),
                    'Entity ID (SP)' => $sp->entity_id,
                    'SSO Endpoint' => url('/saml/sso'),
                    'SLO Endpoint' => url('/saml/slo'),
                    'Metadaten (SP-spezifisch)' => optional($provider->applications->first())->id ? url('/saml/'.$provider->applications->first()->id.'/metadata') : null,
                ])" />
            @endif
        </x-card>

        <x-danger-zone>
            <p class="w-full text-sm text-red-700">
                Der Provider {{ $provider->name }} wird endgültig gelöscht.
                @if ($provider->applications->isNotEmpty()) Verknüpfte Anwendungen verlieren dadurch ihren Provider. @endif
            </p>
            <x-confirm-form :action="route('admin.providers.destroy', $provider)"
                            title="Provider löschen"
                            :message="'Der Provider '.$provider->name.' und seine technische Konfiguration werden endgültig gelöscht.'"
                            label="Provider löschen" size="sm" />
        </x-danger-zone>
    </div>
@endif
@endsection
