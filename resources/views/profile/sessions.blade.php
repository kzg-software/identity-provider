@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Meine Sitzungen</h1>
    <x-confirm-form :action="route('profile.sessions.destroy-others')" method="POST" message="Wirklich alle anderen Sitzungen abmelden?" label="Alle anderen Sitzungen abmelden" />
</div>

<x-table>
    <thead class="bg-gray-50">
        <tr>
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
                <td class="px-4 py-2 text-gray-700">
                    {{ $session->browser ?? '?' }} / {{ $session->platform ?? '?' }} ({{ $session->device ?? '?' }})
                    @if ($session->session_id === $currentSessionId)
                        <x-badge color="laravel" class="ml-1">Diese Sitzung</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2 text-gray-500">{{ $session->ip_address }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_method }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->login_at?->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2 text-gray-500">{{ $session->last_activity_at?->diffForHumans() }}</td>
                <td class="px-4 py-2">
                    @unless ($session->session_id === $currentSessionId)
                        <x-confirm-form :action="route('profile.sessions.destroy', $session)" message="Diese Sitzung beenden?" label="Beenden" />
                    @endunless
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
@endsection
