@extends('layouts.admin')

@section('admin-content')
<x-page-header :title="$directory->name" :back="route('admin.directories.index')" back-label="Alle Verzeichnisse">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.directories.edit', $directory) }}" variant="secondary" size="sm">Bearbeiten</x-button>
        @if ($directory->is_active)
            <form method="POST" action="{{ route('admin.directories.deactivate', $directory) }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Deaktivieren</x-button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.directories.activate', $directory) }}">
                @csrf
                <x-button type="submit" variant="success" size="sm">Aktivieren</x-button>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="mb-4 flex gap-2">
    <x-badge>{{ $directory->type }}</x-badge>
    @if ($directory->is_active)
        <x-badge color="green">aktiv</x-badge>
    @else
        <x-badge>inaktiv</x-badge>
    @endif
</div>

@if (session('ldap_error'))
    <x-alert type="danger">{{ session('ldap_error') }}</x-alert>
@endif

<div class="grid gap-6 md:grid-cols-2">
    <div class="space-y-6">
        <x-card title="Verbindungsdaten">
            <x-dl :rows="[
                'LDAP-Server' => $directory->ldap_server.':'.$directory->ldap_port.($directory->use_ldaps ? ' (LDAPS)' : ''),
                'Base DN' => $directory->base_dn,
                'User DN' => $directory->user_dn,
                'Group DN' => $directory->group_dn,
                'Bind User' => $directory->bind_user,
                'Domain / NetBIOS' => $directory->domain.' / '.$directory->netbios_domain,
                'Kerberos Realm' => $directory->kerberos_realm,
            ]" />
            <form method="POST" action="{{ route('admin.directories.test-connection', $directory) }}" class="mt-4 border-t border-gray-100 pt-4">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Verbindung testen</x-button>
            </form>
        </x-card>

        <x-card title="Synchronisierung"
                description="Voll läuft täglich über den Scheduler, die Gruppen zusätzlich alle 15 Minuten.">
            @php
                $syncRows = [
                    'Letzte Synchronisierung' => $directory->last_sync_at ?? 'noch nie',
                    'Dauer' => $directory->last_sync_duration_seconds !== null ? $directory->last_sync_duration_seconds.'s' : '–',
                    'Benutzer' => $directory->last_sync_user_count,
                    'Gruppen' => $directory->last_sync_group_count,
                    'Fehlende Benutzer' => ['keep' => 'behalten', 'disable' => 'sperren', 'delete' => 'löschen'][$directory->stalePolicy()],
                ];
                if ($directory->hasLoginGroupFilter()) {
                    $syncRows['Nur Gruppen'] = implode(', ', array_map(
                        fn ($g) => \Illuminate\Support\Str::afterLast(\Illuminate\Support\Str::before($g, ','), '='),
                        $directory->loginGroupFilters()
                    ));
                }
                if ($directory->last_sync_removed_count) {
                    $syncRows['Zuletzt entfernt'] = $directory->last_sync_removed_count;
                }
            @endphp
            <x-dl :rows="$syncRows" />
            @if ($directory->last_sync_error)
                <p class="mt-2 text-sm text-red-600">Fehler: {{ $directory->last_sync_error }}</p>
            @endif
            <form method="POST" action="{{ route('admin.directories.sync', $directory) }}" class="mt-4 border-t border-gray-100 pt-4">
                @csrf
                <x-button type="submit" size="sm">Jetzt synchronisieren</x-button>
            </form>
        </x-card>
    </div>

    <div class="space-y-6">
        <x-card title="Werkzeuge zum Testen"
                description="Prüfe schnell, ob Suche, Anmeldung und Filter so funktionieren wie erwartet.">
            <div class="space-y-5">
                <div>
                    <x-input-label value="Benutzer suchen" />
                    <form method="POST" action="{{ route('admin.directories.search-user', $directory) }}" class="mt-1 flex gap-2">
                        @csrf
                        <x-input type="text" name="term" placeholder="sAMAccountName, UPN, Name, E-Mail" required minlength="2" />
                        <x-button type="submit" variant="secondary" size="sm" class="shrink-0">Suchen</x-button>
                    </form>
                </div>
                <div>
                    <x-input-label value="Gruppe suchen" />
                    <form method="POST" action="{{ route('admin.directories.search-group', $directory) }}" class="mt-1 flex gap-2">
                        @csrf
                        <x-input type="text" name="term" placeholder="Gruppenname" required minlength="2" />
                        <x-button type="submit" variant="secondary" size="sm" class="shrink-0">Suchen</x-button>
                    </form>
                </div>
                <div>
                    <x-input-label value="Anmeldung testen" />
                    <form method="POST" action="{{ route('admin.directories.test-authenticate', $directory) }}" class="mt-1 space-y-2">
                        @csrf
                        <x-input type="text" name="username" placeholder="Benutzername" required />
                        <x-input type="password" name="password" placeholder="Passwort" required />
                        <x-button type="submit" variant="secondary" size="sm">Anmelden</x-button>
                    </form>
                </div>
                <div>
                    <x-input-label value="LDAP-Abfrage testen" />
                    <form method="POST" action="{{ route('admin.directories.raw-query', $directory) }}" class="mt-1 space-y-2">
                        @csrf
                        <x-textarea name="filter" rows="3" required class="font-mono text-xs"
                                    placeholder="(&(objectClass=user)(memberOf=CN=IDP-Login,OU=Gruppen,DC=firma,DC=local))">{{ old('filter') }}</x-textarea>
                        <p class="text-xs text-gray-500">
                            Nur der Filter, ohne Suchpfad. Gesucht wird ab
                            <code class="rounded bg-gray-100 px-1">{{ $directory->searchBaseDn() ?? 'automatisch aus der Domäne' }}</code>.
                            Klammern paarweise schließen.
                        </p>
                        <x-button type="submit" variant="secondary" size="sm">Abfragen</x-button>
                    </form>
                </div>
            </div>
        </x-card>

        @if (session('ldap_search_result'))
            @php($r = session('ldap_search_result'))
            <x-card title="Ergebnis">
                @if (! $r['ok'])
                    <x-alert type="danger" class="mb-0">{{ $r['message'] }}</x-alert>
                @elseif (empty($r['results']))
                    <p class="text-sm text-gray-500">Keine Treffer.</p>
                @else
                    <pre class="max-h-96 overflow-auto rounded bg-gray-100 p-3 text-xs">{{ json_encode($r['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </x-card>
        @endif
    </div>
</div>

<x-card title="Synchronisierte Gruppen (max. 50)" :padding="false" class="mt-6">
    <x-table :heads="['Name', 'DN', 'Zuletzt synchronisiert']">
        <tbody class="divide-y divide-gray-100">
            @forelse ($directory->directoryGroups as $group)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-900">{{ $group->name }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500">{{ $group->distinguished_name }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $group->last_synced_at }}</td>
                </tr>
            @empty
                <x-empty-state cell :colspan="3" icon="users" title="Noch keine Gruppen">
                    Führe eine Synchronisierung aus, dann erscheinen die Gruppen hier.
                </x-empty-state>
            @endforelse
        </tbody>
    </x-table>
</x-card>

<div class="mt-6">
    <x-danger-zone>
        <p class="w-full text-sm text-red-700">Löscht die Verbindung samt synchronisierten Gruppen. Die Benutzerkonten bleiben, verlieren aber die Verzeichnis-Zuordnung.</p>
        <x-confirm-form :action="route('admin.directories.destroy', $directory)" :message="'Verzeichnis '.$directory->name.' wirklich löschen?'" label="Verzeichnis löschen" size="sm" />
    </x-danger-zone>
</div>
@endsection
