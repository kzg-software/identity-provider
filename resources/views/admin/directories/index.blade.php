@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Verzeichnisse"
    description="Verbindungen zu Active Directory oder LDAP. Von hier holt das System Benutzer und Gruppen und prüft Anmeldungen.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.directories.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Verzeichnis anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Name', 'Typ', 'Domain', 'Status', 'Letzte Synchronisierung', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($directories as $directory)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <a href="{{ route('admin.directories.show', $directory) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $directory->name }}</a>
                </td>
                <td class="px-4 py-2"><x-badge>{{ $directory->type }}</x-badge></td>
                <td class="px-4 py-2 text-gray-600">{{ $directory->domain }}</td>
                <td class="px-4 py-2">
                    @if ($directory->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge>inaktiv</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2 text-gray-500">
                    @if ($directory->last_sync_at)
                        {{ $directory->last_sync_at }}
                        @if ($directory->last_sync_error)
                            <x-badge color="red">Fehler</x-badge>
                        @endif
                    @else
                        <span class="text-gray-400">noch nie</span>
                    @endif
                </td>
                <td class="px-4 py-2 text-right">
                    <x-button tag="a" href="{{ route('admin.directories.edit', $directory) }}" variant="secondary" size="sm">Bearbeiten</x-button>
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="6" icon="server" title="Noch kein Verzeichnis verbunden">
                Ohne Verzeichnis funktionieren nur lokale Konten. Lege eine Verbindung zu Active Directory oder LDAP an.
                <x-slot:action>
                    <x-button tag="a" href="{{ route('admin.directories.create') }}" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />Verzeichnis anlegen
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
