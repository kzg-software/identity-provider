@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Benutzerverwaltung</h1>
    <x-button tag="a" href="{{ route('admin.users.create') }}" size="sm"><x-icon name="plus" class="h-4 w-4" />Lokalen Benutzer anlegen</x-button>
</div>

<form method="GET" class="mb-4">
    <x-input type="text" name="q" placeholder="Suche nach Name, Benutzername, E-Mail" value="{{ request('q') }}" />
</form>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Benutzername</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Quelle</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Letzter Login</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($users as $user)
            <tr>
                <td class="px-4 py-2"><a href="{{ route('admin.users.show', $user) }}" class="text-laravel-600 hover:text-laravel-700 font-medium">{{ $user->name }}</a></td>
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
                <td class="px-4 py-2 text-gray-500">{{ $user->last_login_at }}</td>
                <td class="px-4 py-2">
                    <div class="flex justify-end gap-2">
                        @if (! $user->is_admin && $user->is_active && $user->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.impersonate', $user) }}" class="inline">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm"><x-icon name="login" class="h-4 w-4" />Anmelden als</x-button>
                            </form>
                        @endif
                        @if ($user->id !== auth()->id())
                            <x-confirm-form :action="route('admin.users.destroy', $user)"
                                            message="{{ $user->name }} wirklich entfernen?"
                                            label="Löschen" size="sm" />
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-3 text-gray-400">Keine Benutzer gefunden.</td></tr>
        @endforelse
    </tbody>
</x-table>

<div class="mt-4">{{ $users->links() }}</div>
@endsection
