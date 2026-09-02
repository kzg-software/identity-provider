@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Benutzer"
    description="Alle Konten in diesem System: lokal angelegte und die aus verbundenen Verzeichnissen synchronisierten.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.users.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Lokalen Benutzer anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<form method="GET" class="mb-4">
    <x-input type="search" name="q" placeholder="Nach Name, Benutzername oder E-Mail suchen" value="{{ request('q') }}" />
</form>

<x-table :heads="['Name', 'Benutzername', 'Quelle', 'Status', 'Letzter Login', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($users as $user)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $user->name }}</a>
                </td>
                <td class="px-4 py-2 text-gray-600">{{ $user->username }}</td>
                <td class="px-4 py-2">
                    <x-badge>{{ $user->auth_source }}</x-badge>
                    @if ($user->is_admin)
                        <x-badge color="laravel">Admin</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2">
                    @if ($user->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge color="red">gesperrt</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2 text-gray-500">{{ $user->last_login_at ?? 'noch nie' }}</td>
                <td class="px-4 py-2">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                        @if (! $user->is_admin && $user->is_active && $user->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.impersonate', $user) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm">Anmelden als</x-button>
                            </form>
                        @endif
                        @if ($user->id !== auth()->id())
                            <x-confirm-form :action="route('admin.users.destroy', $user)"
                                            message="{{ $user->name }} wirklich entfernen?"
                                            label="Benutzer löschen" size="sm" icon-only />
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="6" icon="users" title="Keine Benutzer gefunden">
                @if (request('q'))
                    Für „{{ request('q') }}" gibt es keinen Treffer. Passe die Suche an oder lege einen lokalen Benutzer an.
                @else
                    Sobald sich jemand anmeldet oder ein Verzeichnis synchronisiert wird, tauchen die Konten hier auf.
                @endif
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>

<div class="mt-4">{{ $users->links() }}</div>
@endsection
