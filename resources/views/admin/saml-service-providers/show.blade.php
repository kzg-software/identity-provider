@extends('layouts.admin')

@section('admin-content')
<x-page-header :title="$sp->name" :back="route('admin.saml-service-providers.index')" back-label="Alle Service Provider">
    <x-slot:actions>
        @if ($sp->is_active)
            <x-badge color="green">aktiv</x-badge>
        @else
            <x-badge>inaktiv</x-badge>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Grunddaten"
            description="Entity ID und ACS URL bekommst du aus den Metadaten des Service Providers.">
        <form method="POST" action="{{ route('admin.saml-service-providers.update', $sp) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label value="Name" />
                <x-input type="text" name="name" value="{{ $sp->name }}" required />
            </div>
            <div>
                <x-input-label value="Entity ID (SP)" />
                <x-input type="text" name="entity_id" value="{{ $sp->entity_id }}" required />
                <p class="mt-1 text-xs text-gray-500">Muss exakt dem &lt;saml:Issuer&gt; entsprechen, den der Service Provider in seiner AuthnRequest sendet.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label value="ACS URL" />
                    <x-input type="url" name="acs_url" value="{{ $sp->acs_url }}" required />
                </div>
                <div>
                    <x-input-label value="Single Logout URL (optional)" />
                    <x-input type="url" name="slo_url" value="{{ $sp->slo_url }}" />
                </div>
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
                        <option value="{{ $value }}" @selected($sp->name_id_format === $value)>{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="SP-Zertifikat (PEM, optional)" />
                <x-textarea name="certificate" rows="4" class="font-mono text-xs">{{ $sp->certificate }}</x-textarea>
                <p class="mt-1 text-xs text-gray-500">Nötig, wenn signierte AuthnRequests geprüft werden sollen.</p>
            </div>
            <div class="space-y-2">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="sign_assertions" value="1" :checked="$sp->sign_assertions" /> Assertions signieren</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="sign_responses" value="1" :checked="$sp->sign_responses" /> Responses signieren</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="encrypt_assertions" value="1" :checked="$sp->encrypt_assertions" /> Assertions verschlüsseln</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="require_signed_requests" value="1" :checked="$sp->require_signed_requests" /> Signierte AuthnRequests erforderlich</label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700"><x-checkbox name="is_active" value="1" :checked="$sp->is_active" /> Service Provider aktiv</label>
            </div>
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>

    <x-card title="Metadaten & Endpunkte" description="Diese Werte trägst du beim Service Provider ein.">
        <x-dl mono :rows="[
            'Entity ID (IdP)' => url('/saml/metadata'),
            'Entity ID (SP)' => $sp->entity_id,
            'SSO Endpoint' => url('/saml/sso'),
            'SLO Endpoint' => url('/saml/slo'),
            'Metadaten (SP-spezifisch)' => url('/saml/'.$sp->application_id.'/metadata'),
        ]" />
    </x-card>

    <x-card title="Attribut-Mapping"
            description="Legt fest, welches Benutzerattribut unter welchem SAML-Attributnamen übertragen wird.">
        <x-table :heads="['SAML-Attribut', 'Benutzerattribut', '']" class="mb-4">
            <tbody class="divide-y divide-gray-100">
                @forelse ($sp->attributeMappings as $mapping)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $mapping->saml_attribute }}</code></td>
                        <td class="px-3 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $mapping->user_attribute }}</code></td>
                        <td class="px-3 py-2 text-right">
                            <x-confirm-form :action="route('admin.saml-service-providers.mappings.destroy', [$sp, $mapping])" message="Mapping entfernen?" label="Entfernen" size="sm" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state cell :colspan="3" icon="signpost" title="Kein Mapping">
                        Ohne Mapping werden keine zusätzlichen Attribute übertragen.
                    </x-empty-state>
                @endforelse
            </tbody>
        </x-table>
        <form method="POST" action="{{ route('admin.saml-service-providers.mappings.store', $sp) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <x-input type="text" name="saml_attribute" placeholder="SAML-Attribut" required class="!w-56" />
            <x-input type="text" name="user_attribute" placeholder="z. B. email, groups" required class="!w-56" />
            <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
        </form>
    </x-card>

    <x-card title="Zugriffsregeln"
            description="Ohne Regeln haben alle angemeldeten Benutzer Zugriff. Deny hat immer Vorrang vor Allow.">
        <x-table :heads="['Effekt', 'Typ', 'Wert', 'Priorität', '']" class="mb-4">
            <tbody class="divide-y divide-gray-100">
                @forelse ($sp->application->accessPolicies as $policy)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2"><x-badge :color="$policy->effect === 'deny' ? 'red' : 'green'">{{ $policy->effect }}</x-badge></td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_type }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_value }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->priority }}</td>
                        <td class="px-3 py-2 text-right">
                            <x-confirm-form :action="route('admin.saml-service-providers.policies.destroy', [$sp, $policy])" message="Regel löschen?" label="Löschen" size="sm" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state cell :colspan="5" icon="users" title="Keine Regeln">
                        Alle angemeldeten Benutzer haben Zugriff.
                    </x-empty-state>
                @endforelse
            </tbody>
        </x-table>
        <form method="POST" action="{{ route('admin.saml-service-providers.policies.store', $sp) }}" class="flex flex-wrap items-end gap-2">
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
        <p class="w-full text-sm text-red-700">Der Service Provider {{ $sp->name }} und alle zugehörigen Zugriffsregeln und Attribut-Mappings werden endgültig gelöscht.</p>
        <x-confirm-form :action="route('admin.saml-service-providers.destroy', $sp)"
                        title="Service Provider löschen"
                        :message="'Der Service Provider '.$sp->name.' und alle zugehörigen Zugriffsregeln und Attribut-Mappings werden endgültig gelöscht. Das lässt sich nicht rückgängig machen.'"
                        label="Service Provider löschen" size="sm" />
    </x-danger-zone>
</div>
@endsection
