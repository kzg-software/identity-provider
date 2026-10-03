@extends('layouts.admin')

@section('admin-content')
@php $provider = $application->provider; @endphp

<x-page-header :title="$application->name" :back="route('admin.applications.index')" back-label="Alle Anwendungen">
    <x-slot:actions>
        @if ($application->is_active)
            <x-badge color="green">aktiv</x-badge>
        @else
            <x-badge>inaktiv</x-badge>
        @endif
        @if ($application->maintenance_mode)<x-badge color="amber">Wartung</x-badge>@endif
        <x-provider-badge :provider="$provider" />
    </x-slot:actions>
</x-page-header>

@if (session('plain_client_secret'))
    <x-alert type="warning">
        <strong>Client Secret (nur jetzt sichtbar):</strong>
        <code class="rounded bg-white/50 px-1">{{ session('plain_client_secret') }}</code>
        <div class="mt-1 text-xs">Jetzt sicher speichern. Nach dem Verlassen der Seite lässt es sich nicht mehr anzeigen.</div>
    </x-alert>
@endif

<x-tabs :base="route('admin.applications.show', $application)" :current="$tab" :tabs="[
    'allgemein' => 'Allgemein',
    'provider' => 'Provider',
    'zugriff' => 'Zugriff',
    'provisionierung' => 'Provisionierung',
    'darstellung' => 'Darstellung',
]" />

@if ($tab === 'allgemein')
    <x-card title="Grunddaten" description="Name und Beschreibung sieht auch der Benutzer auf der Zustimmungsseite.">
        <form method="POST" action="{{ route('admin.applications.update', $application) }}" class="space-y-4">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="allgemein">
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
            </div>
            <div>
                <x-input-label value="Bereich (optional)" />
                <x-input type="text" name="category" list="category-suggestions" value="{{ $application->category }}" placeholder="z. B. Allgemein" />
                <datalist id="category-suggestions">
                    @foreach ($categories as $category)<option value="{{ $category }}">@endforeach
                </datalist>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label value="Anmeldung" />
                    <x-select name="login_mode">
                        @foreach (['user_choice' => 'Anmeldeseite anzeigen', 'auto_redirect' => 'Automatisch weiterleiten', 'windows_sso' => 'Windows SSO erzwingen', 'windows_sso_fallback' => 'Windows SSO, sonst Anmeldeseite', 'specific_provider' => 'Bestimmter externer Provider'] as $value => $label)
                            <option value="{{ $value }}" @selected($application->login_mode === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label>Externer IdP-Schlüssel
                        <x-field-info>Nur relevant, wenn oben „Bestimmter externer Identity Provider" gewählt ist (z. B. entra-id, keycloak).</x-field-info>
                    </x-input-label>
                    <x-input type="text" name="preferred_provider" value="{{ $application->preferred_provider }}" placeholder="z. B. entra-id, keycloak" />
                </div>
            </div>
            <div>
                <x-input-label>Nach Daten fragen (Zustimmung)
                    <x-field-info>Wie oft der Benutzer der Datenweitergabe an diese Anwendung zustimmen muss.</x-field-info>
                </x-input-label>
                <x-select name="consent_mode">
                    @foreach (['first_time' => 'Nur beim ersten Mal fragen', 'always' => 'Immer fragen', 'on_scope_change' => 'Erneut fragen bei geänderten Berechtigungen', 'skip' => 'Nie fragen'] as $value => $label)
                        <option value="{{ $value }}" @selected($application->consent_mode === $value)>{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="space-y-2">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="consent_required" value="1" :checked="$application->consent_required" />
                    Zustimmungsseite anzeigen
                </label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="is_active" value="1" :checked="$application->is_active" />
                    Anwendung aktiv
                </label>
            </div>

            <div class="space-y-3 border-t border-gray-100 pt-4">
                <h4 class="text-sm font-semibold text-gray-900">Wartungsmodus (nur diese Anwendung)</h4>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="maintenance_mode" value="1" :checked="$application->maintenance_mode" />
                    In Wartung, Anmeldung an dieser Anwendung ist gesperrt
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
    </x-card>
@endif

@if ($tab === 'provider')
    <div class="space-y-6">
        @if ($provider)
            <x-card title="Zugeordneter Provider">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-gray-900">{{ $provider->name }}</span>
                            <x-provider-badge :provider="$provider" />
                        </div>
                        <p class="mt-1 text-sm {{ $provider->isConfigured() && $provider->is_active ? 'text-emerald-600' : 'text-amber-600' }}">
                            @if (! $provider->is_active)
                                Provider ist deaktiviert – Anmeldungen schlagen fehl.
                            @elseif ($provider->isConfigured())
                                Aktiv und vollständig konfiguriert.
                            @else
                                Konfiguration unvollständig – bitte im Provider prüfen.
                            @endif
                        </p>
                        @if ($provider->isOidc() && $provider->oauthClient)
                            <p class="mt-2 text-xs text-gray-500">Client ID: <code class="rounded bg-gray-100 px-1">{{ $provider->oauthClient->client_id }}</code></p>
                        @elseif ($provider->isSaml() && $provider->samlServiceProvider)
                            <p class="mt-2 text-xs text-gray-500">Entity ID: <code class="rounded bg-gray-100 px-1">{{ $provider->samlServiceProvider->entity_id }}</code></p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <x-button tag="a" href="{{ route('admin.providers.show', $provider) }}" size="sm">Provider öffnen</x-button>
                        <x-confirm-form :action="route('admin.applications.provider.detach', $application)" method="DELETE"
                                        title="Provider entfernen"
                                        message="Die Verknüpfung wird aufgehoben. Der Provider selbst bleibt bestehen und kann anderen Anwendungen zugewiesen werden."
                                        label="Verknüpfung aufheben" variant="secondary" size="sm" />
                    </div>
                </div>
            </x-card>

            <x-card title="Provider wechseln">
                <form method="POST" action="{{ route('admin.applications.provider.attach', $application) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <x-select name="provider_id" class="!w-72">
                        @foreach ($allProviders as $p)
                            <option value="{{ $p->id }}" @selected($p->id === $provider->id)>
                                {{ $p->name }} ({{ $p->typeLabel() }}){{ $p->applications->count() > 1 ? ' – von mehreren Anwendungen genutzt' : '' }}
                            </option>
                        @endforeach
                    </x-select>
                    <x-button type="submit" size="sm">Zuweisen</x-button>
                </form>
            </x-card>
        @else
            <x-card title="Kein Provider zugeordnet"
                    description="Diese Anwendung erscheint im Portal, kann aber noch keine Anmeldung durchführen.">
                <div class="flex flex-wrap gap-2">
                    <x-button tag="a" href="{{ route('admin.providers.create', ['type' => 'oidc', 'application' => $application->id]) }}" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />OAuth-/OIDC-Provider anlegen
                    </x-button>
                    <x-button tag="a" href="{{ route('admin.providers.create', ['type' => 'saml', 'application' => $application->id]) }}" variant="secondary" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />SAML-Provider anlegen
                    </x-button>
                </div>

                @if ($unassignedProviders->isNotEmpty())
                    <form method="POST" action="{{ route('admin.applications.provider.attach', $application) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
                        @csrf
                        <div>
                            <x-input-label value="Bestehenden (freien) Provider zuweisen" />
                            <x-select name="provider_id" class="!w-72">
                                @foreach ($unassignedProviders as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->typeLabel() }})</option>
                                @endforeach
                            </x-select>
                        </div>
                        <x-button type="submit" size="sm">Zuweisen</x-button>
                    </form>
                @endif
            </x-card>
        @endif
    </div>
@endif

@if ($tab === 'zugriff')
    <x-card title="Zugriffsregeln"
            description="Ohne Regeln haben alle angemeldeten Benutzer Zugriff. Deny hat immer Vorrang vor Allow.">
        <x-table :heads="['Effekt', 'Typ', 'Wert', 'Priorität', '']" class="mb-4">
            <tbody class="divide-y divide-gray-100">
                @forelse ($application->accessPolicies as $policy)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2"><x-badge :color="$policy->effect === 'deny' ? 'red' : 'green'">{{ $policy->effect }}</x-badge></td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_type }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->subject_value }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $policy->priority }}</td>
                        <td class="px-3 py-2 text-right">
                            <x-confirm-form :action="route('admin.applications.policies.destroy', [$application, $policy])" message="Regel löschen?" label="Löschen" size="sm" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state cell :colspan="5" icon="users" title="Keine Regeln">
                        Alle angemeldeten Benutzer haben Zugriff.
                    </x-empty-state>
                @endforelse
            </tbody>
        </x-table>
        <form method="POST" action="{{ route('admin.applications.policies.store', $application) }}" class="flex flex-wrap items-end gap-2">
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

    <form method="POST" action="{{ route('admin.applications.update', $application) }}" class="mt-6">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="zugriff">
        <x-card title="Netzwerk und Uhrzeit"
                description="Zusätzliche Einschränkungen, die für alle Benutzer gelten, auch wenn eine Zugriffsregel sie zulässt. Leer lassen für keine Einschränkung.">
            <div class="space-y-5">
                <div>
                    <x-input-label>Erlaubte IP-Adressen
                        <x-field-info example="10.0.0.0/8, 192.168.1.0/24, 203.0.113.7">
                            IP-Adressen oder Netze in CIDR-Schreibweise (IPv4 und IPv6), getrennt durch Komma oder Zeilenumbruch. Nur Anmeldungen von diesen Adressen werden zugelassen. Hinter einem Reverse Proxy muss TRUSTED_PROXIES gesetzt sein.
                        </x-field-info>
                    </x-input-label>
                    <x-textarea name="allowed_ip_ranges" rows="3">{{ old('allowed_ip_ranges', $application->allowed_ip_ranges) }}</x-textarea>
                    @error('allowed_ip_ranges')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-input-label>Erlaubte Zeiten
                        <x-field-info example="Mo-Fr 08:00-18:00">
                            Eine Zeile pro Zeitfenster: Wochentage (Mo Di Mi Do Fr Sa So, auch als Bereich "Mo-Fr" oder Liste "Mo,Mi") gefolgt von Uhrzeit von-bis. Fenster über Mitternacht sind möglich ("Fr 22:00-06:00"). Es gilt die Zeitzone des Systems.
                        </x-field-info>
                    </x-input-label>
                    <x-textarea name="access_time_windows" rows="3">{{ old('access_time_windows', $application->access_time_windows) }}</x-textarea>
                    @error('access_time_windows')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-button type="submit" size="sm">Speichern</x-button>
            </div>
        </x-card>
    </form>
@endif

@if ($tab === 'provisionierung')
    @php $scim = $application->scimConnection; @endphp
    @error('scim')<x-alert type="danger">{{ $message }}</x-alert>@enderror

    <x-card title="Automatische Provisionierung (SCIM 2.0)"
            description="Legt Benutzer und Gruppen in der Zielanwendung automatisch an, aktualisiert sie und entfernt sie wieder. Bereitgestellt werden aktive Benutzer, die laut Zugriffsregeln auf diese Anwendung zugreifen dürfen. Ohne Zugriffsregeln sind das alle aktiven Benutzer.">
        <form method="POST" action="{{ route('admin.applications.scim.save', $application) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <div>
                <x-input-label>SCIM-Basis-URL
                    <x-field-info :required="true" example="https://app.example.de/scim/v2">
                        Adresse der SCIM-Schnittstelle der Zielanwendung, ohne /Users am Ende.
                    </x-field-info>
                </x-input-label>
                <x-input type="url" name="base_url" value="{{ old('base_url', $scim?->base_url) }}" required />
                @error('base_url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <x-input-label>Bearer-Token
                    <x-field-info :required="! $scim">
                        Zugriffstoken, das die Zielanwendung für die SCIM-Schnittstelle ausgestellt hat. Wird verschlüsselt gespeichert und nicht wieder angezeigt.
                    </x-field-info>
                </x-input-label>
                <x-input type="password" name="auth_token" autocomplete="off" placeholder="{{ $scim ? 'Unverändert lassen, um das Token zu behalten' : '' }}" :required="! $scim" />
                @error('auth_token')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex flex-wrap gap-6">
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="sync_users" value="1" :checked="old('sync_users', $scim?->sync_users ?? true)" />Benutzer synchronisieren
                </label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="sync_groups" value="1" :checked="old('sync_groups', $scim?->sync_groups ?? false)" />Gruppen synchronisieren (nur Verzeichnisgruppen)
                </label>
                <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-gray-700">
                    <x-checkbox name="is_active" value="1" :checked="old('is_active', $scim?->is_active ?? true)" />Verbindung aktiv
                </label>
            </div>
            <div>
                <x-input-label>Wenn ein Benutzer den Zugriff verliert oder gelöscht wird</x-input-label>
                <x-select name="on_removal" class="!w-72">
                    <option value="deactivate" @selected(old('on_removal', $scim?->on_removal ?? 'deactivate') === 'deactivate')>Konto in der Zielanwendung deaktivieren</option>
                    <option value="delete" @selected(old('on_removal', $scim?->on_removal) === 'delete')>Konto in der Zielanwendung löschen</option>
                </x-select>
            </div>
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>

    @if ($scim)
        <x-card title="Status" class="mt-6">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">Letzte Synchronisierung</dt>
                    <dd class="text-gray-900">{{ $scim->last_synced_at?->format('d.m.Y H:i') ?? 'noch nie' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Ergebnis</dt>
                    <dd>
                        @if ($scim->last_status === 'ok')<x-badge color="green">in Ordnung</x-badge>
                        @elseif ($scim->last_status === 'error')<x-badge color="red">Fehler</x-badge>
                        @else<x-badge>offen</x-badge>@endif
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">Bereitgestellt</dt>
                    <dd class="text-gray-900">
                        {{ $scim->resources()->where('resource_type', 'user')->where('deactivated', false)->count() }} Benutzer,
                        {{ $scim->resources()->where('resource_type', 'group')->count() }} Gruppen
                    </dd>
                </div>
            </dl>
            @if ($scim->last_error)
                <pre class="mt-3 whitespace-pre-wrap rounded bg-red-50 p-3 text-xs text-red-700">{{ $scim->last_error }}</pre>
            @endif
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('admin.applications.scim.test', $application) }}">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm">Verbindung testen</x-button>
                </form>
                <form method="POST" action="{{ route('admin.applications.scim.sync', $application) }}">
                    @csrf
                    <x-button type="submit" size="sm">Jetzt synchronisieren</x-button>
                </form>
                <x-confirm-form :action="route('admin.applications.scim.destroy', $application)" message="SCIM-Verbindung wirklich entfernen? Bereits angelegte Konten in der Zielanwendung bleiben bestehen." label="Verbindung entfernen" size="sm" />
            </div>
            <p class="mt-3 text-xs text-gray-400">Der Abgleich läuft automatisch alle 10 Minuten und kurz nach Änderungen an Benutzern oder Zugriffsregeln.</p>
        </x-card>
    @endif
@endif

@if ($tab === 'darstellung')
    <x-card title="Darstellung im Portal">
        <form method="POST" action="{{ route('admin.applications.update', $application) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf @method('PUT')
            <input type="hidden" name="section" value="darstellung">
            <div>
                <x-input-label>Sichtbarkeit
                    <x-field-info>„Portal“: Kachel im Benutzerportal für berechtigte Benutzer. „Verborgen“: nur direkter Zugriff / SSO.</x-field-info>
                </x-input-label>
                <x-select name="visibility">
                    <option value="portal" @selected($application->isVisibleInPortal())>Im Benutzerportal anzeigen</option>
                    <option value="hidden" @selected(! $application->isVisibleInPortal())>Verborgen (nur direkter Zugriff / SSO)</option>
                </x-select>
            </div>
            <div>
                <x-input-label value="Icon / Logo (PNG, JPG, GIF, WebP, SVG – max. 5 MB)" />
                <div class="mt-1 flex items-center gap-4">
                    @if ($application->logo_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($application->logo_path) }}" alt="" class="h-12 w-12 rounded object-contain bg-gray-50">
                    @else
                        <span class="flex h-12 w-12 items-center justify-center rounded bg-laravel-50 text-laravel-600"><x-icon name="building" class="h-5 w-5" /></span>
                    @endif
                    <x-image-upload-input name="logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" preview-class="h-12 w-12" />
                </div>
                @error('logo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-button type="submit" size="sm">Speichern</x-button>
        </form>
    </x-card>
@endif

@if ($tab === 'allgemein')
    <x-danger-zone class="mt-6">
        <p class="w-full text-sm text-red-700">Die Anwendung {{ $application->name }} wird endgültig gelöscht. Ein zugeordneter Provider bleibt bestehen.</p>
        <x-confirm-form :action="route('admin.applications.destroy', $application)"
                        title="Anwendung löschen"
                        :message="'Die Anwendung '.$application->name.' wird endgültig gelöscht. Das lässt sich nicht rückgängig machen. Der zugeordnete Provider bleibt bestehen.'"
                        label="Anwendung löschen" size="sm" />
    </x-danger-zone>
@endif
@endsection
