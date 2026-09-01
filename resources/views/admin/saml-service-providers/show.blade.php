@extends('layouts.admin')

@section('admin-content')
<div x-data="{ locked: true }">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $sp->name }}</h1>
        <button type="button" @click="locked = !locked"
            :class="locked ? 'bg-gray-100 text-gray-600 border-gray-300' : 'bg-amber-50 text-amber-700 border-amber-300'"
            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium">
            <x-icon name="lock-closed" x-show="locked" class="h-4 w-4" />
            <x-icon name="lock-open" x-show="!locked" x-cloak class="h-4 w-4" />
            <span x-text="locked ? 'Schutzmodus aktiv' : 'Bearbeitung entsperrt'"></span>
        </button>
    </div>

    <p class="text-xs text-gray-500 mb-4" x-show="locked">Diese Seite ist schreibgeschützt. Klicke auf "Schutzmodus aktiv", um Änderungen vorzunehmen.</p>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="space-y-6">
            <x-card title="Service Provider">
                <fieldset :disabled="locked" class="space-y-4 disabled:opacity-60">
                <form method="POST" action="{{ route('admin.saml-service-providers.update', $sp) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <div>
                        <x-input-label value="Name" />
                        <x-input type="text" name="name" value="{{ $sp->name }}" required />
                    </div>
                    <div>
                        <x-input-label value="Entity ID (SP)" />
                        <x-input type="text" name="entity_id" value="{{ $sp->entity_id }}" required />
                        <p class="mt-1 text-xs text-gray-500">Muss exakt dem &lt;saml:Issuer&gt; entsprechen, den der Service Provider in seiner AuthnRequest sendet (z.B. bei Zammad dessen eigener Metadaten-Endpunkt, nicht nur die Basis-URL).</p>
                    </div>
                    <div>
                        <x-input-label value="ACS URL" />
                        <x-input type="url" name="acs_url" value="{{ $sp->acs_url }}" required />
                    </div>
                    <div>
                        <x-input-label value="Single Logout URL" />
                        <x-input type="url" name="slo_url" value="{{ $sp->slo_url }}" />
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
                                <option value="{{ $value }}" {{ $sp->name_id_format === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div>
                        <x-input-label value="SP-Zertifikat (PEM)" />
                        <x-textarea name="certificate" rows="4">{{ $sp->certificate }}</x-textarea>
                    </div>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="sign_assertions" value="1" :checked="$sp->sign_assertions" /> Assertions signieren</label>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="sign_responses" value="1" :checked="$sp->sign_responses" /> Responses signieren</label>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="encrypt_assertions" value="1" :checked="$sp->encrypt_assertions" /> Assertions verschlüsseln</label>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="require_signed_requests" value="1" :checked="$sp->require_signed_requests" /> Signierte AuthnRequests erforderlich</label>
                        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none"><x-checkbox name="is_active" value="1" :checked="$sp->is_active" /> Aktiv</label>
                    </div>
                    <x-button type="submit" size="sm">Speichern</x-button>
                </form>

                <div class="pt-4 border-t border-gray-100">
                    <x-confirm-form :action="route('admin.saml-service-providers.destroy', $sp)" title="Service Provider löschen" message="Der Service Provider „{{ $sp->name }}“ und alle zugehörigen Zugriffsregeln/Attribut-Mappings werden endgültig gelöscht. Diese Aktion kann nicht rückgängig gemacht werden." label="Service Provider löschen" />
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
                        @forelse ($sp->application->accessPolicies as $policy)
                            <tr>
                                <td class="px-3 py-2"><x-badge :color="$policy->effect === 'deny' ? 'red' : 'green'">{{ $policy->effect }}</x-badge></td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->subject_type }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->subject_value }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $policy->priority }}</td>
                                <td class="px-3 py-2">
                                    <x-confirm-form :action="route('admin.saml-service-providers.policies.destroy', [$sp, $policy])" message="Regel löschen?" label="Löschen" size="sm" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-2 text-gray-400">Keine Regeln — alle authentifizierten Benutzer haben Zugriff.</td></tr>
                        @endforelse
                    </tbody>
                </x-table>
                <form method="POST" action="{{ route('admin.saml-service-providers.policies.store', $sp) }}" class="flex flex-wrap gap-2 items-end">
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
                </fieldset>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Metadaten &amp; Endpunkte">
                <x-dl :rows="[
                    'Entity ID (IdP)' => url('/saml/metadata'),
                    'Entity ID (SP)' => $sp->entity_id,
                    'SSO Endpoint' => url('/saml/sso'),
                    'SLO Endpoint' => url('/saml/slo'),
                    'Metadata (SP-spezifisch)' => url('/saml/'.$sp->application_id.'/metadata'),
                ]" />
            </x-card>

            <x-card title="Attribut-Mapping">
                <fieldset :disabled="locked" class="disabled:opacity-60">
                <x-table class="mb-4">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">SAML-Attribut</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-500">Benutzerattribut</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sp->attributeMappings as $mapping)
                            <tr>
                                <td class="px-3 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $mapping->saml_attribute }}</code></td>
                                <td class="px-3 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $mapping->user_attribute }}</code></td>
                                <td class="px-3 py-2">
                                    <x-confirm-form :action="route('admin.saml-service-providers.mappings.destroy', [$sp, $mapping])" message="Mapping entfernen?" label="Entfernen" size="sm" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-table>
                <form method="POST" action="{{ route('admin.saml-service-providers.mappings.store', $sp) }}" class="flex flex-wrap gap-2 items-end">
                    @csrf
                    <x-input type="text" name="saml_attribute" placeholder="SAML-Attribut" required class="!w-56" />
                    <x-input type="text" name="user_attribute" placeholder="z.B. email, groups" required class="!w-56" />
                    <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
                </form>
                </fieldset>
            </x-card>
        </div>
    </div>
</div>
@endsection
