@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-start mb-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">{{ $directory->name }}</h1>
        <div class="mt-1 flex gap-2">
            <x-badge>{{ $directory->type }}</x-badge>
            @if ($directory->is_active)
                <x-badge color="green">aktiv</x-badge>
            @else
                <x-badge>inaktiv</x-badge>
            @endif
        </div>
    </div>
    <div class="flex gap-2">
        <x-button tag="a" href="{{ route('admin.directories.edit', $directory) }}" variant="secondary" size="sm">Bearbeiten</x-button>
        @if ($directory->is_active)
            <form method="POST" action="{{ route('admin.directories.deactivate', $directory) }}" class="inline">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Deaktivieren</x-button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.directories.activate', $directory) }}" class="inline">
                @csrf
                <x-button type="submit" variant="success" size="sm">Aktivieren</x-button>
            </form>
        @endif
        <x-confirm-form :action="route('admin.directories.destroy', $directory)" message="Verzeichnis wirklich löschen?" label="Löschen" size="sm" />
    </div>
</div>

@if (session('ldap_error'))
    <x-alert type="danger">{{ session('ldap_error') }}</x-alert>
@endif

<div class="grid md:grid-cols-2 gap-6">
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
            <form method="POST" action="{{ route('admin.directories.test-connection', $directory) }}" class="mt-4 pt-4 border-t border-gray-100">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Verbindung testen</x-button>
            </form>
        </x-card>

        <x-card title="Synchronisierung">
            @php
                $syncRows = [
                    'Letzte Synchronisierung' => $directory->last_sync_at ?? 'nie',
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
            <p class="text-xs text-gray-400 mt-2">Voll synchronisiert wird täglich über den Scheduler; die Gruppen zusätzlich alle 15 Minuten.</p>
            @if ($directory->last_sync_error)
                <p class="text-sm text-red-600 mt-2">Fehler: {{ $directory->last_sync_error }}</p>
            @endif
            <form method="POST" action="{{ route('admin.directories.sync', $directory) }}" class="mt-4 pt-4 border-t border-gray-100">
                @csrf
                <x-button type="submit" size="sm">Jetzt synchronisieren</x-button>
            </form>
        </x-card>
    </div>

    <div class="space-y-6">
        <x-card title="Benutzer suchen">
            <form method="POST" action="{{ route('admin.directories.search-user', $directory) }}" class="flex gap-2">
                @csrf
                <x-input type="text" name="term" placeholder="sAMAccountName, UPN, Name, E-Mail" required minlength="2" />
                <x-button type="submit" variant="secondary" size="sm" class="shrink-0">Suchen</x-button>
            </form>
        </x-card>

        <x-card title="Gruppe suchen">
            <form method="POST" action="{{ route('admin.directories.search-group', $directory) }}" class="flex gap-2">
                @csrf
                <x-input type="text" name="term" placeholder="Gruppenname" required minlength="2" />
                <x-button type="submit" variant="secondary" size="sm" class="shrink-0">Suchen</x-button>
            </form>
        </x-card>

        <x-card title="Testbenutzer authentifizieren">
            <form method="POST" action="{{ route('admin.directories.test-authenticate', $directory) }}" class="space-y-2">
                @csrf
                <x-input type="text" name="username" placeholder="Benutzername" required />
                <x-input type="password" name="password" placeholder="Passwort" required />
                <x-button type="submit" variant="secondary" size="sm">Authentifizieren</x-button>
            </form>
        </x-card>

        <x-card title="LDAP-Abfrage testen">
            <form method="POST" action="{{ route('admin.directories.raw-query', $directory) }}" class="space-y-2">
                @csrf
                <x-textarea name="filter" rows="3" required class="font-mono text-xs"
                            placeholder="(&(objectClass=user)(memberOf=CN=IDP-Login,OU=Gruppen,DC=firma,DC=local))">{{ old('filter') }}</x-textarea>
                <p class="text-xs text-gray-500">
                    Nur der Filter, ohne Suchpfad. Gesucht wird ab
                    <code class="bg-gray-100 px-1 rounded">{{ $directory->searchBaseDn() ?? 'automatisch aus der Domäne' }}</code>.
                    Klammern paarweise schließen.
                </p>
                <x-button type="submit" variant="secondary" size="sm">Abfragen</x-button>
            </form>
        </x-card>
    </div>
</div>

@if (session('ldap_search_result'))
    @php($r = session('ldap_search_result'))
    <x-card title="Ergebnis" class="mt-6">
        @if (! $r['ok'])
            <x-alert type="danger" class="mb-0">{{ $r['message'] }}</x-alert>
        @elseif (empty($r['results']))
            <p class="text-sm text-gray-500">Keine Treffer.</p>
        @else
            <pre class="text-xs bg-gray-100 p-3 rounded overflow-auto max-h-96">{{ json_encode($r['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
    </x-card>
@endif

<x-card title="Synchronisierte Gruppen (max. 50)" :padding="false" class="mt-6">
    <x-table>
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
                <th class="px-4 py-2 text-left font-medium text-gray-500">DN</th>
                <th class="px-4 py-2 text-left font-medium text-gray-500">Zuletzt synchronisiert</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($directory->directoryGroups as $group)
                <tr>
                    <td class="px-4 py-2 text-gray-900">{{ $group->name }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500">{{ $group->distinguished_name }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $group->last_synced_at }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-3 text-gray-400">Noch keine Gruppen synchronisiert.</td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-card>
@endsection
