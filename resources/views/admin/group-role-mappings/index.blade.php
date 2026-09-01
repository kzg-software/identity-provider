@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Gruppen-zu-Rollen-Mapping</h1>

<x-card title="Neues Mapping" class="mb-6">
    <form method="POST" action="{{ route('admin.group-role-mappings.store') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div class="flex-1 min-w-[16rem]">
            <x-input-label value="AD-Gruppe" />
            <x-select name="directory_group_id" required>
                <option value="">– auswählen –</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}">{{ $group->directory->name }} / {{ $group->name }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-input-label value="Rolle" />
            <x-input type="text" name="role" placeholder="admin" required class="!w-56" />
        </div>
        <div>
            <x-input-label value="Claims (JSON, optional)" />
            <x-input type="text" name="claims" placeholder='{"roles":["admin"]}' class="!w-56" />
        </div>
        <x-button type="submit">+</x-button>
    </form>
    @if ($groups->isEmpty())
        <p class="text-sm text-gray-500 mt-3">Es sind noch keine AD-Gruppen synchronisiert. Führen Sie zuerst eine Verzeichnis-Synchronisierung durch.</p>
    @endif
</x-card>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Verzeichnis</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">AD-Gruppe</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Rolle</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Claims</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($mappings as $mapping)
            <tr>
                <td class="px-4 py-2 text-gray-600">{{ $mapping->directoryGroup->directory->name }}</td>
                <td class="px-4 py-2 text-gray-900">{{ $mapping->directoryGroup->name }}</td>
                <td class="px-4 py-2"><x-badge color="laravel">{{ $mapping->role }}</x-badge></td>
                <td class="px-4 py-2 text-xs text-gray-400">{{ $mapping->claims ? json_encode($mapping->claims) : '–' }}</td>
                <td class="px-4 py-2 text-right">
                    <x-confirm-form :action="route('admin.group-role-mappings.destroy', $mapping)" message="Mapping löschen?" label="Löschen" size="sm" />
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-3 text-gray-400">Keine Mappings konfiguriert.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
