@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Neuer SAML Service Provider</h1>

<x-card>
    <form method="POST" action="{{ route('admin.saml-service-providers.store') }}" class="space-y-4">
        @csrf
        <div class="grid grid-cols-2 gap-4">
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ old('name') }}" required />
            </div>
            <div>
                <x-input-label value="Entity ID" />
                <x-input type="text" name="entity_id" value="{{ old('entity_id') }}" placeholder="https://sp.example.com/metadata" required />
            </div>
            <div>
                <x-input-label value="ACS URL" />
                <x-input type="url" name="acs_url" value="{{ old('acs_url') }}" placeholder="https://sp.example.com/saml/acs" required />
            </div>
            <div>
                <x-input-label value="Single Logout URL" />
                <x-input type="url" name="slo_url" value="{{ old('slo_url') }}" />
            </div>
            <div>
                <x-input-label value="NameID-Format" />
                <x-select name="name_id_format">
                    @foreach ([
                        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => 'persistent',
                        'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => 'transient',
                        'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress' => 'emailAddress',
                        'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified' => 'unspecified',
                    ] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="Login-Verhalten" />
                <x-select name="login_mode">
                    @foreach (['user_choice' => 'Login-Seite anzeigen', 'auto_redirect' => 'Automatische Weiterleitung', 'windows_sso' => 'Windows SSO erzwingen', 'windows_sso_fallback' => 'Windows SSO mit Fallback', 'specific_provider' => 'Bestimmter externer Provider'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="Consent-Modus" />
                <x-select name="consent_mode">
                    @foreach (['skip' => 'Überspringen', 'first_time' => 'Nur beim ersten Mal', 'always' => 'Immer', 'on_scope_change' => 'Bei Änderung'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>

        <div>
            <x-input-label value="SP-Zertifikat (PEM, optional — zur Prüfung signierter AuthnRequests)" />
            <x-textarea name="certificate" rows="4">{{ old('certificate') }}</x-textarea>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="sign_assertions" value="1" checked /> Assertions signieren</label>
            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="sign_responses" value="1" checked /> Responses signieren</label>
            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="encrypt_assertions" value="1" /> Assertions verschlüsseln</label>
            <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="require_signed_requests" value="1" /> Signierte AuthnRequests erforderlich</label>
        </div>

        <x-button type="submit">Anlegen</x-button>
    </form>
</x-card>
@endsection
