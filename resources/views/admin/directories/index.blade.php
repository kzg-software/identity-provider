@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Verzeichnisse</h1>
    <x-button tag="a" href="{{ route('admin.directories.create') }}" size="sm"><x-icon name="plus" class="h-4 w-4" />Verzeichnis anlegen</x-button>
</div>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Typ</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Domain</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Letzte Sync</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($directories as $directory)
            <tr>
                <td class="px-4 py-2"><a href="{{ route('admin.directories.show', $directory) }}" class="text-laravel-600 hover:text-laravel-700 font-medium">{{ $directory->name }}</a></td>
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
                        <span class="text-gray-400">nie</span>
                    @endif
                </td>
                <td class="px-4 py-2 text-right">
                    <x-button tag="a" href="{{ route('admin.directories.edit', $directory) }}" variant="secondary" size="sm">Bearbeiten</x-button>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-3 text-gray-400">Keine Verzeichnisse konfiguriert.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
