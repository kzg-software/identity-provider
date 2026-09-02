@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Alle Sitzungen"
    description="Jede aktive Anmeldung über alle Benutzer hinweg. Eine Sitzung zu widerrufen meldet das Gerät sofort ab." />

<x-table :heads="['Benutzer', 'Gerät', 'IP', 'Methode', 'Angemeldet seit', 'Letzte Aktivität', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($sessions as $session)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-900">{{ $session->user?->name }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $session->browser ?? '?' }} / {{ $session->platform ?? '?' }} ({{ $session->device ?? '?' }})</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->ip_address }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_method }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_at?->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->last_activity_at?->diffForHumans() }}</td>
                <td class="px-4 py-2 text-right">
                    <x-confirm-form :action="route('admin.sessions.destroy', $session)" message="Diese Sitzung widerrufen? Das Gerät wird sofort abgemeldet." label="Widerrufen" size="sm" />
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="7" icon="monitor" title="Keine aktiven Sitzungen">
                Sobald sich jemand anmeldet, erscheint die Sitzung hier.
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
<div class="mt-4">{{ $sessions->links() }}</div>
@endsection
