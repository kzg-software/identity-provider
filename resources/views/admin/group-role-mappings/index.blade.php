@extends('layouts.admin')

@php
    $roleHints = [
        'admin' => 'Macht zum Administrator mit Zugriff auf den Administrationsbereich.',
    ];
    $groupCatalog = $groups->map(fn ($g) => ['name' => $g->name, 'directory' => $g->directory?->name, 'members' => $g->directory_users_count])->values();
    $totalViaMapping = collect($roleStats)->sum('via_mapping');
@endphp

@section('admin-content')
<x-page-header
    title="Rollen-Mapping"
    description="Legt fest, welche Verzeichnis-Gruppe welche Rolle bekommt. Wer in einer zugeordneten Gruppe ist, erhält die Rolle automatisch." />

{{-- Überblick --}}
<div class="mb-6 grid gap-4 sm:grid-cols-3">
    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
        <p class="text-2xl font-semibold text-gray-900">{{ $mappings->count() }}</p>
        <p class="text-sm text-gray-500">{{ $mappings->count() === 1 ? 'Zuordnung' : 'Zuordnungen' }} von Gruppe zu Rolle</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
        <p class="text-2xl font-semibold text-gray-900">{{ count($roleStats) }}</p>
        <p class="text-sm text-gray-500">{{ count($roleStats) === 1 ? 'Rolle wird' : 'Rollen werden' }} vergeben</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
        <p class="text-2xl font-semibold text-gray-900">{{ $totalViaMapping }}</p>
        <p class="text-sm text-gray-500">Rollenvergaben an Benutzer über Gruppen</p>
    </div>
</div>

{{-- Neues Mapping --}}
<x-card title="Neue Zuordnung" description="Drei Angaben: welche Gruppe, welche Rolle, in welchem Verzeichnis." class="mb-6">
    <form method="POST" action="{{ route('admin.group-role-mappings.store') }}"
          x-data="{
              group: @js(old('group', '')),
              role: @js(old('role', request('role', ''))),
              catalog: @js($groupCatalog),
              get match() { const g = this.group.trim().toLowerCase(); return g ? this.catalog.find(c => c.name.toLowerCase() === g) : null; },
          }"
          class="space-y-5">
        @csrf

        <div class="grid gap-5 lg:grid-cols-3">
            <div>
                <x-input-label>
                    <span class="mr-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-laravel-50 text-xs font-semibold text-laravel-700">1</span>Gruppe
                </x-input-label>
                <x-input type="text" name="group" list="known-ad-groups" required x-model="group" autocomplete="off"
                         placeholder="Gruppenname eingeben oder auswählen" />
                <datalist id="known-ad-groups">
                    @foreach ($groups as $group)
                        <option value="{{ $group->name }}">{{ $group->directory?->name }}</option>
                    @endforeach
                </datalist>
                <p class="mt-1.5 text-xs" x-show="group.trim() !== ''" x-cloak>
                    <template x-if="match">
                        <span class="text-emerald-700">
                            Bekannte Gruppe aus „<span x-text="match.directory"></span>“ mit <span x-text="match.members"></span> Mitgliedern.
                        </span>
                    </template>
                    <template x-if="! match">
                        <span class="text-amber-700">Noch nicht synchronisiert. Das Mapping greift, sobald ein Benutzer in einer Gruppe mit diesem Namen auftaucht.</span>
                    </template>
                </p>
                <p class="mt-1.5 text-xs text-gray-500" x-show="group.trim() === ''">
                    @if ($groups->isEmpty())
                        Es gibt noch keine synchronisierten Gruppen. Den Namen kannst du trotzdem direkt eintragen.
                    @else
                        {{ $groups->count() }} bekannte Gruppen stehen zur Auswahl. Groß- und Kleinschreibung ist egal.
                    @endif
                </p>
            </div>

            <div>
                <x-input-label>
                    <span class="mr-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-laravel-50 text-xs font-semibold text-laravel-700">2</span>Rolle
                </x-input-label>
                <x-input type="text" name="role" required x-model="role" autocomplete="off" placeholder="z. B. admin" />
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($knownRoles as $knownRole)
                        <button type="button" @click="role = @js($knownRole)"
                                :class="role === @js($knownRole) ? 'border-laravel-300 bg-laravel-50 text-laravel-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                                class="rounded-full border px-2.5 py-0.5 text-xs font-medium transition">{{ $knownRole }}</button>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-gray-500">
                    <code>admin</code> macht zum Administrator. Andere Rollen stehen in den Tokens und Attributen der Anwendungen (Claim <code>roles</code>). Keine Leerzeichen.
                </p>
            </div>

            <div>
                <x-input-label>
                    <span class="mr-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600">3</span>Verzeichnis <span class="ml-1 text-xs font-normal text-gray-400">optional</span>
                </x-input-label>
                <x-select name="directory_id">
                    <option value="">Alle Verzeichnisse</option>
                    @foreach ($directories as $directory)
                        <option value="{{ $directory->id }}" @selected(old('directory_id') == $directory->id)>{{ $directory->name }}</option>
                    @endforeach
                </x-select>
                <p class="mt-1.5 text-xs text-gray-500">Nur nötig, wenn derselbe Gruppenname in mehreren Verzeichnissen vorkommt. Für bekannte Gruppen wird es automatisch bestimmt.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
            <x-button type="submit"><x-icon name="plus" class="h-4 w-4" />Zuordnung anlegen</x-button>
            <p class="text-sm text-gray-500" x-show="group.trim() !== '' && role.trim() !== ''" x-cloak>
                Mitglieder von <strong class="font-medium text-gray-900" x-text="group"></strong> erhalten die Rolle <strong class="font-medium text-gray-900" x-text="role"></strong>.
            </p>
        </div>
        @error('group')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('role')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('directory_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('claims')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </form>
</x-card>

{{-- Zuordnungen, nach Rolle gruppiert --}}
@forelse ($roleStats as $role => $stat)
    <x-card class="mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-6">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-badge color="laravel" class="!text-sm">{{ $role }}</x-badge>
                    @if ($role === 'admin')<x-badge color="amber">Administrator</x-badge>@endif
                </div>
                <p class="mt-1.5 text-sm text-gray-500">
                    {{ $roleHints[$role] ?? 'Eigene Rolle, die in Tokens und Attributen der Anwendungen auftaucht.' }}
                </p>
            </div>
            <div class="text-right text-sm text-gray-500">
                <p>
                    <strong class="font-semibold text-gray-900">{{ $stat['via_mapping'] }}</strong>
                    {{ $stat['via_mapping'] === 1 ? 'Benutzer hat' : 'Benutzer haben' }} die Rolle über Gruppen
                </p>
                @if ($stat['manual'] > 0)
                    <p class="text-xs">zusätzlich {{ $stat['manual'] }} manuell vergeben</p>
                @endif
                @if ($stat['sample'])
                    <p class="mt-0.5 text-xs text-gray-400">z. B. {{ implode(', ', $stat['sample']) }}</p>
                @endif
            </div>
        </div>

        <x-table :heads="['Gruppe', 'Verzeichnis', 'Status', '']" class="!rounded-none !border-0 !shadow-none">
            <tbody class="divide-y divide-gray-100">
                @foreach ($stat['mappings'] as $mapping)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium text-gray-900">
                            {{ $mapping->groupLabel() }}
                            @unless ($mapping->directory_group_id)
                                <span class="ml-1 text-xs font-normal text-gray-400">(nach Name)</span>
                            @endunless
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $mapping->directoryLabel() }}</td>
                        <td class="px-4 py-2">
                            @if ($mapping->directory_group_id)
                                <x-badge color="green">Gruppe verknüpft</x-badge>
                                <span class="ml-1 text-xs text-gray-500">
                                    {{ $groups->firstWhere('id', $mapping->directory_group_id)?->directory_users_count ?? 0 }} Mitglieder
                                </span>
                            @else
                                <x-badge color="amber">Noch nicht gefunden</x-badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <x-confirm-form :action="route('admin.group-role-mappings.destroy', $mapping)"
                                            message="Zuordnung „{{ $mapping->groupLabel() }} → {{ $role }}“ löschen? Betroffene Benutzer verlieren die Rolle beim nächsten Verzeichnis-Abgleich."
                                            label="Löschen" size="sm" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-table>

        <form method="POST" action="{{ route('admin.group-role-mappings.store') }}"
              class="flex flex-wrap items-center gap-2 border-t border-gray-100 bg-gray-50 px-4 py-3 sm:px-6">
            @csrf
            <input type="hidden" name="role" value="{{ $role }}">
            <x-input type="text" name="group" list="known-ad-groups" required placeholder="Weitere Gruppe für „{{ $role }}“" class="!w-64" autocomplete="off" />
            <x-button type="submit" variant="secondary" size="sm"><x-icon name="plus" class="h-4 w-4" />Hinzufügen</x-button>
        </form>
    </x-card>
@empty
    <x-card class="mb-6">
        <x-empty-state icon="signpost" title="Noch keine Zuordnung">
            Ohne Zuordnung bekommt niemand aus dem Verzeichnis automatisch eine Rolle. Lege oben die erste an, zum Beispiel die Gruppe deiner IT-Abteilung für die Rolle <code>admin</code>.
        </x-empty-state>
    </x-card>
@endforelse

@unless (isset($roleStats['admin']))
    <x-alert type="info">
        Es gibt keine Zuordnung für die Rolle <code>admin</code>. Konten aus dem Verzeichnis können so nicht über eine Gruppe zum Administrator werden. Lokale Administratoren sind davon nicht betroffen.
    </x-alert>
@endunless

{{-- So funktioniert es --}}
<x-card title="So funktioniert das Rollen-Mapping">
    <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-600">
        <li>Das System liest beim Verzeichnis-Abgleich (automatisch alle paar Minuten oder per „Synchronisieren“ unter <a href="{{ route('admin.directories.index') }}" class="text-laravel-600 hover:text-laravel-700">Verzeichnisse</a>) die Gruppen jedes Benutzers.</li>
        <li>Für jede Gruppe mit Zuordnung erhält der Benutzer die zugehörige Rolle. Auch verschachtelte Gruppen zählen mit.</li>
        <li>Verlässt ein Benutzer die Gruppe, entfällt die Rolle beim nächsten Abgleich wieder. Manuell vergebene Rollen unter <a href="{{ route('admin.users.index') }}" class="text-laravel-600 hover:text-laravel-700">Benutzer</a> bleiben davon unberührt.</li>
        <li>Neue Zuordnungen und Löschungen wirken also nicht sofort, sondern ab dem nächsten Abgleich.</li>
    </ol>
</x-card>
@endsection
