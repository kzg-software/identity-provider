@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Audit-Log</h1>

<x-card class="mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
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
            <x-input type="text" name="event" list="events" value="{{ $filters['event'] ?? '' }}" placeholder="z.B. login.success" class="!w-56" />
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
            <x-input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="!w-56" />
        </div>
        <div>
            <x-input-label value="Bis" />
            <x-input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="!w-56" />
        </div>
        <div class="flex gap-2">
            <x-button type="submit" size="sm">Filtern</x-button>
            <x-button tag="a" href="{{ route('admin.audit-log.index') }}" variant="secondary" size="sm">Zurücksetzen</x-button>
        </div>
    </form>
</x-card>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Zeitpunkt</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Benutzer</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Ereignis</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Anwendung</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">IP</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Metadaten</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @foreach ($logs as $log)
            <tr>
                <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
                <td class="px-4 py-2 text-gray-700">{{ $log->user?->name ?? '—' }}</td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $log->event }}</code></td>
                <td class="px-4 py-2 text-gray-600">{{ $log->application?->name }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $log->ip_address }}</td>
                <td class="px-4 py-2 text-xs text-gray-400">{{ $log->metadata ? json_encode($log->metadata) : '' }}</td>
            </tr>
        @endforeach
    </tbody>
</x-table>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
