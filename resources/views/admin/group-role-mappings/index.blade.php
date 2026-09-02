@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Rollen-Mapping"
    description="Ordne Verzeichnis-Gruppen internen Rollen zu. Wer in einer zugeordneten Gruppe ist, bekommt die Rolle automatisch. Die Rolle admin macht zum Administrator." />

<x-card title="Neues Mapping" class="mb-6">
    <form method="POST" action="{{ route('admin.group-role-mappings.store') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="min-w-[16rem] flex-1">
            <x-input-label value="Gruppe" />
            <x-input type="text" name="group" list="known-ad-groups" required
                     value="{{ old('group') }}" placeholder="z. B. IDP-Login oder GG_App_Admins" />
            <datalist id="known-ad-groups">
                @foreach ($groups as $group)
                    <option value="{{ $group->name }}">{{ $group->directory?->name }}</option>
                @endforeach
            </datalist>
        </div>
        <div>
            <x-input-label value="Verzeichnis" />
            <x-select name="directory_id" class="!w-48">
                <option value="">alle</option>
                @foreach ($directories as $directory)
                    <option value="{{ $directory->id }}" @selected(old('directory_id') == $directory->id)>{{ $directory->name }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-input-label value="Rolle" />
            <x-input type="text" name="role" value="{{ old('role') }}" placeholder="admin" required class="!w-40" />
        </div>
        <div>
            <x-input-label value="Claims (JSON, optional)" />
            <x-input type="text" name="claims" value="{{ old('claims') }}" placeholder='{"roles":["admin"]}' class="!w-56" />
        </div>
        <x-button type="submit"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
    </form>
    <p class="mt-3 text-xs text-gray-500">
        @if ($groups->isEmpty())
            Noch keine Gruppen synchronisiert, deshalb fehlen Vorschläge. Du kannst den Namen trotzdem direkt eintragen oder zuerst ein Verzeichnis synchronisieren.
        @else
            {{ $groups->count() }} bekannte Gruppen stehen als Vorschlag bereit. Der Name wird ohne Rücksicht auf Groß- und Kleinschreibung geprüft.
        @endif
    </p>
</x-card>

<x-table :heads="['Verzeichnis', 'Gruppe', 'Rolle', 'Claims', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($mappings as $mapping)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-600">{{ $mapping->directoryLabel() }}</td>
                <td class="px-4 py-2 text-gray-900">
                    {{ $mapping->groupLabel() }}
                    @unless ($mapping->directory_group_id)
                        <span class="ml-1 text-xs text-gray-400">(nach Name)</span>
                    @endunless
                </td>
                <td class="px-4 py-2"><x-badge color="laravel">{{ $mapping->role }}</x-badge></td>
                <td class="px-4 py-2 text-xs text-gray-400">{{ $mapping->claims ? json_encode($mapping->claims) : '–' }}</td>
                <td class="px-4 py-2 text-right">
                    <x-confirm-form :action="route('admin.group-role-mappings.destroy', $mapping)" message="Mapping löschen?" label="Löschen" size="sm" />
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="5" icon="signpost" title="Noch kein Mapping">
                Ohne Mapping bekommt niemand aus dem Verzeichnis automatisch eine Rolle. Lege oben das erste an.
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
