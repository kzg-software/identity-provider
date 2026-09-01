@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Aktive Sessions (alle Benutzer)</h1>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Benutzer</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Gerät</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">IP</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Methode</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Angemeldet seit</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Letzte Aktivität</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @foreach ($sessions as $session)
            <tr>
                <td class="px-4 py-2 text-gray-900">{{ $session->user?->name }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $session->browser ?? '?' }} / {{ $session->platform ?? '?' }} ({{ $session->device ?? '?' }})</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->ip_address }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_method }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_at?->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->last_activity_at?->diffForHumans() }}</td>
                <td class="px-4 py-2">
                    <x-confirm-form :action="route('admin.sessions.destroy', $session)" message="Session widerrufen?" label="Widerrufen" size="sm" />
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
<div class="mt-4">{{ $sessions->links() }}</div>
@endsection
