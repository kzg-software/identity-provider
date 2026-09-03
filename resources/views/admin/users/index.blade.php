@extends('layouts.admin')

@section('admin-content')
@php
    $selectableIds = $users->getCollection()
        ->pluck('id')
        ->reject(fn ($id) => $id === auth()->id())
        ->map(fn ($id) => (string) $id)
        ->values();
@endphp

<x-page-header
    title="Benutzer"
    description="Alle Konten in diesem System: lokal angelegte und die aus verbundenen Verzeichnissen synchronisierten.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.users.export', request()->only('q')) }}" variant="secondary" size="sm">
            <x-icon name="download" class="h-4 w-4" />Exportieren
        </x-button>
        <x-button tag="a" href="{{ route('admin.users.import') }}" variant="secondary" size="sm">
            <x-icon name="arrow-path" class="h-4 w-4" />Importieren
        </x-button>
        <x-button tag="a" href="{{ route('admin.users.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Lokalen Benutzer anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<div x-data="{ selected: [], confirmDelete: false }">
    <form method="GET" class="mb-4">
        <x-input type="search" name="q" placeholder="Nach Name, Benutzername oder E-Mail suchen" value="{{ request('q') }}" />
    </form>

    {{-- Massenaktionen --}}
    <div x-show="selected.length > 0" x-cloak
         class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-laravel-200 bg-laravel-50 px-4 py-2.5 text-sm">
        <span class="font-medium text-laravel-800"><span x-text="selected.length"></span> ausgewählt</span>

        <form method="POST" action="{{ route('admin.users.bulk') }}" x-ref="bulkForm" class="flex flex-wrap items-center gap-2">
            @csrf
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <input type="hidden" name="action" x-ref="bulkAction">
            <x-button type="button" size="sm" variant="secondary" @click="$refs.bulkAction.value = 'lock'; $refs.bulkForm.submit()">Sperren</x-button>
            <x-button type="button" size="sm" variant="secondary" @click="$refs.bulkAction.value = 'unlock'; $refs.bulkForm.submit()">Entsperren</x-button>
            <x-button type="button" size="sm" variant="danger" @click="confirmDelete = true">Löschen</x-button>
        </form>

        <button type="button" class="text-xs text-laravel-700 hover:underline" @click="selected = []">Auswahl aufheben</button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-0 px-4 py-2">
                        @if ($selectableIds->isNotEmpty())
                            <input type="checkbox" class="rounded border-gray-300"
                                   @change="selected = $event.target.checked ? {{ \Illuminate\Support\Js::from($selectableIds) }} : []"
                                   :checked="selected.length === {{ $selectableIds->count() }}">
                        @endif
                    </th>
                    @foreach (['Name', 'Benutzername', 'Quelle', 'Status', 'Letzter Login', ''] as $h)
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500">{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($users as $user)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2">
                            @if ($user->id !== auth()->id())
                                <input type="checkbox" class="rounded border-gray-300" x-model="selected" value="{{ $user->id }}">
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $user->name }}</a>
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $user->username }}</td>
                        <td class="px-4 py-2">
                            <x-badge>{{ $user->auth_source }}</x-badge>
                            @if ($user->is_admin)<x-badge color="laravel">Admin</x-badge>@endif
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
                                                    :message="$user->name.' wirklich entfernen?'"
                                                    label="Benutzer löschen" size="sm" icon-only />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-empty-state cell :colspan="7" icon="users" title="Keine Benutzer gefunden">
                        @if (request('q'))
                            Für diese Suche gibt es keinen Treffer. Passe die Suche an oder lege einen lokalen Benutzer an.
                        @else
                            Sobald sich jemand anmeldet oder ein Verzeichnis synchronisiert wird, tauchen die Konten hier auf.
                        @endif
                    </x-empty-state>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>

    {{-- Bestätigung Massen-Löschung --}}
    <div x-show="confirmDelete" x-cloak style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="confirmDelete = false">
        <div class="fixed inset-0 bg-gray-900/50" @click="confirmDelete = false"></div>
        <div class="relative w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h3 class="mb-2 text-base font-semibold text-gray-900">Benutzer löschen</h3>
            <p class="mb-6 text-sm text-gray-600"><span x-text="selected.length"></span> Benutzer werden endgültig entfernt. Das eigene Konto und der letzte lokale Administrator werden übersprungen.</p>
            <div class="flex justify-end gap-3">
                <x-button type="button" variant="secondary" size="sm" @click="confirmDelete = false">Abbrechen</x-button>
                <x-button type="button" variant="danger" size="sm" @click="$refs.bulkAction.value = 'delete'; $refs.bulkForm.submit()">Endgültig löschen</x-button>
            </div>
        </div>
    </div>
</div>
@endsection
