@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Audit-Log"
    description="Wer hat wann was gemacht: Anmeldungen, Änderungen an Anwendungen und Benutzern, Zustimmungen. Nur lesbar, nichts lässt sich hier ändern." />

<x-card title="Filter" class="mb-4">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <x-input-label value="Benutzer" />
            <x-select name="user_id" class="!w-56">
                <option value="">Alle</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(($filters['user_id'] ?? null) == $user->id)>{{ $user->name }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-input-label value="Ereignis" />
            <x-input type="text" name="event" list="events" value="{{ $filters['event'] ?? '' }}" placeholder="z. B. login.success" class="!w-56" />
            <datalist id="events">
                @foreach ($events as $event)
                    <option value="{{ $event }}">
                @endforeach
            </datalist>
        </div>
        <div>
            <x-input-label value="Anwendung" />
            <x-select name="application_id" class="!w-56">
                <option value="">Alle</option>
                @foreach ($applications as $application)
                    <option value="{{ $application->id }}" @selected(($filters['application_id'] ?? null) == $application->id)>{{ $application->name }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-input-label value="Von" />
            <x-input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="!w-44" />
        </div>
        <div>
            <x-input-label value="Bis" />
            <x-input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="!w-44" />
        </div>
        <div class="flex gap-2">
            <x-button type="submit" size="sm">Filtern</x-button>
            <x-button tag="a" href="{{ route('admin.audit-log.index') }}" variant="secondary" size="sm">Zurücksetzen</x-button>
        </div>
    </form>
</x-card>

<x-table :heads="['Zeitpunkt', 'Benutzer', 'Ereignis', 'Anwendung', 'IP', 'Metadaten']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                <td class="px-4 py-2 text-gray-700">{{ $log->user?->name ?? '–' }}</td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $log->event }}</code></td>
                <td class="px-4 py-2 text-gray-600">{{ $log->application?->name }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $log->ip_address }}</td>
                <td class="px-4 py-2 text-xs text-gray-400">{{ $log->metadata ? json_encode($log->metadata) : '' }}</td>
            </tr>
        @empty
            <x-empty-state cell :colspan="6" icon="journal" title="Keine Einträge">
                Für diese Filter gibt es nichts. Setze die Filter zurück oder warte auf neue Ereignisse.
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
