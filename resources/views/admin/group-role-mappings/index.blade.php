@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-2">Gruppen-zu-Rollen-Mapping</h1>
<p class="text-sm text-gray-500 mb-6">
    Ordnet Verzeichnis-Gruppen internen Rollen zu. Der Gruppenname wird
    gross-/kleinschreibungsunabhängig gegen die Gruppen des Benutzers geprüft.
    Bekannte Gruppen aus der letzten Synchronisierung werden vorgeschlagen, es
    lässt sich aber jeder Name eintragen.
</p>

<x-card title="Neues Mapping" class="mb-6">
    <form method="POST" action="{{ route('admin.group-role-mappings.store') }}" class="flex flex-wrap gap-3 items-end">
        @csrf
        <div class="flex-1 min-w-[16rem]">
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
        <x-button type="submit">Hinzufügen</x-button>
    </form>
    @if ($groups->isEmpty())
        <p class="text-sm text-gray-500 mt-3">
            Noch keine Gruppen synchronisiert &ndash; Vorschläge fehlen daher. Der Name lässt sich trotzdem
            direkt eintragen, oder zuerst eine Verzeichnis-Synchronisierung ausführen.
        </p>
    @else
        <p class="text-sm text-gray-400 mt-3">{{ $groups->count() }} bekannte Gruppen als Vorschlag.</p>
    @endif
</x-card>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Verzeichnis</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Gruppe</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Rolle</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Claims</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($mappings as $mapping)
            <tr>
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
            <tr><td colspan="5" class="px-4 py-3 text-gray-400">Keine Mappings konfiguriert.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
