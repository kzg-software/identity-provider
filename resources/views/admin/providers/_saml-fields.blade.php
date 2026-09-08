{{--
    SAML-2.0-Providerfelder. Erwartet: $nameIdFormats (array format => label)
    Optional: $sp (SamlServiceProvider) für Vorbelegung beim Bearbeiten
--}}
@php
    $sp = $sp ?? null;
    $required = $required ?? true;
@endphp

<div class="space-y-5">
    <div>
        <x-input-label>Entity ID (SP)
            <x-field-info :required="true" example="https://sp.example.com/metadata">
                Eindeutige Kennung des Service Providers. Muss exakt dem &lt;saml:Issuer&gt;
                entsprechen, den die Anwendung in ihrer AuthnRequest sendet.
            </x-field-info>
        </x-input-label>
        <x-input type="text" name="entity_id" value="{{ old('entity_id', $sp?->entity_id) }}" :required="$required" placeholder="https://sp.example.com/metadata" />
    </div>

    <div>
        <x-input-label>ACS URL
            <x-field-info :required="true" example="https://sp.example.com/saml/acs">
                Assertion Consumer Service – hierhin schickt das System die signierte
                SAML-Antwort nach erfolgreicher Anmeldung.
            </x-field-info>
        </x-input-label>
        <x-input type="url" name="acs_url" value="{{ old('acs_url', $sp?->acs_url) }}" :required="$required" placeholder="https://sp.example.com/saml/acs" />
    </div>

    <div>
        <x-input-label>NameID-Format
            <x-field-info>Format der Benutzerkennung in der Assertion. „persistent“ ist die datenschutzfreundliche Voreinstellung.</x-field-info>
        </x-input-label>
        <x-select name="name_id_format">
            @foreach ($nameIdFormats as $value => $label)
                <option value="{{ $value }}" @selected(old('name_id_format', $sp?->name_id_format) === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
    </div>
</div>

<details class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4" @if($errors->hasAny(['slo_url','certificate','session_lifetime_minutes'])) open @endif>
    <summary class="cursor-pointer text-sm font-medium text-gray-700">Erweiterte Einstellungen</summary>
    <div class="mt-4 space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label>Single Logout URL (optional)
                    <x-field-info>Adresse des SP für abgemeldete Sitzungen (SLO).</x-field-info>
                </x-input-label>
                <x-input type="url" name="slo_url" value="{{ old('slo_url', $sp?->slo_url) }}" />
            </div>
            <div>
                <x-input-label>Session-Laufzeit (Minuten, optional)
                    <x-field-info>Wie lange die SAML-Sitzung gültig bleibt (SessionNotOnOrAfter). Leer = 8 Stunden.</x-field-info>
                </x-input-label>
                <x-input type="number" name="session_lifetime_minutes" value="{{ old('session_lifetime_minutes', $sp?->session_lifetime_minutes) }}" />
            </div>
        </div>

        <div>
            <x-input-label>SP-Zertifikat (PEM, optional)
                <x-field-info>
                    Öffentliches Zertifikat des Service Providers. Nur nötig, wenn signierte
                    AuthnRequests geprüft oder Assertions verschlüsselt werden sollen.
                </x-field-info>
            </x-input-label>
            <x-textarea name="certificate" rows="4" class="font-mono text-xs">{{ old('certificate', $sp?->certificate) }}</x-textarea>
        </div>

        <div>
            <x-input-label>Signaturalgorithmus</x-input-label>
            <x-select name="sign_algorithm">
                @foreach (['sha256', 'sha384', 'sha512', 'sha1'] as $alg)
                    <option value="{{ $alg }}" @selected(old('sign_algorithm', $sp?->sign_algorithm ?? 'sha256') === $alg)>{{ strtoupper($alg) }}</option>
                @endforeach
            </x-select>
        </div>

        <div class="space-y-2">
            @php
                $flags = [
                    'sign_assertions' => ['Assertions signieren', true],
                    'sign_responses' => ['Responses signieren', true],
                    'encrypt_assertions' => ['Assertions verschlüsseln', false],
                    'want_name_id_encrypted' => ['NameID verschlüsseln', false],
                    'require_signed_requests' => ['Signierte AuthnRequests erforderlich', false],
                ];
            @endphp
            @foreach ($flags as $name => [$label, $default])
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <input type="hidden" name="_has_{{ $name }}" value="1">
                    <x-checkbox name="{{ $name }}" value="1" :checked="$sp ? $sp->{$name} : old($name, $default)" />
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>
</details>
