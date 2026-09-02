@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="SAML Service Provider anlegen"
    :back="route('admin.saml-service-providers.index')" back-label="Alle Service Provider"
    description="Verbindet eine SAML-2.0-Anwendung mit diesem System. Die technischen Werte findest du in den Metadaten des Service Providers." />

<form method="POST" action="{{ route('admin.saml-service-providers.store') }}" class="space-y-6">
    @csrf

    <x-card title="1. Anwendung" description="Name für die Verwaltung und die Kennungen aus den SP-Metadaten.">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ old('name') }}" required autofocus />
            </div>
            <div>
                <x-input-label value="Entity ID" />
                <x-input type="text" name="entity_id" value="{{ old('entity_id') }}" placeholder="https://sp.example.com/metadata" required />
            </div>
            <div>
                <x-input-label value="ACS URL" />
                <x-input type="url" name="acs_url" value="{{ old('acs_url') }}" placeholder="https://sp.example.com/saml/acs" required />
                <p class="mt-1 text-xs text-gray-500">Hierhin schickt das System die SAML-Antwort nach dem Login.</p>
            </div>
            <div>
                <x-input-label value="Single Logout URL (optional)" />
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
        </div>
    </x-card>

    <x-card title="2. Wie melden sich Benutzer an?">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label value="Anmeldung" />
                <x-select name="login_mode">
                    @foreach (['user_choice' => 'Anmeldeseite anzeigen', 'auto_redirect' => 'Automatisch weiterleiten', 'windows_sso' => 'Windows SSO erzwingen', 'windows_sso_fallback' => 'Windows SSO, sonst Anmeldeseite', 'specific_provider' => 'Bestimmter externer Provider'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="Nach Daten fragen (Zustimmung)" />
                <x-select name="consent_mode">
                    @foreach (['skip' => 'Nie fragen', 'first_time' => 'Nur beim ersten Mal fragen', 'always' => 'Immer fragen', 'on_scope_change' => 'Erneut fragen bei Änderung'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>
    </x-card>

    <x-card title="3. Sicherheit" description="Die Voreinstellungen passen für die meisten Service Provider.">
        <div class="space-y-4">
            <div>
                <x-input-label value="SP-Zertifikat (PEM, optional)" />
                <x-textarea name="certificate" rows="4" class="font-mono text-xs">{{ old('certificate') }}</x-textarea>
                <p class="mt-1 text-xs text-gray-500">Nur nötig, wenn signierte AuthnRequests geprüft werden sollen.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="sign_assertions" value="1" checked /> Assertions signieren</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="sign_responses" value="1" checked /> Responses signieren</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="encrypt_assertions" value="1" /> Assertions verschlüsseln</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="require_signed_requests" value="1" /> Signierte AuthnRequests erforderlich</label>
            </div>
        </div>
    </x-card>

    <div class="flex gap-3">
        <x-button type="submit">Service Provider anlegen</x-button>
        <x-button tag="a" href="{{ route('admin.saml-service-providers.index') }}" variant="link">Abbrechen</x-button>
    </div>
</form>
@endsection
