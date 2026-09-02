@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Meine Sitzungen"
    description="Alle Geräte, auf denen du gerade angemeldet bist. Kommt dir eine Sitzung fremd vor, beende sie und ändere dein Passwort.">
    <x-slot:actions>
        <x-confirm-form :action="route('profile.sessions.destroy-others')" method="POST"
                        message="Wirklich alle anderen Sitzungen abmelden?"
                        label="Alle anderen abmelden" size="sm" />
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Gerät', 'IP', 'Methode', 'Angemeldet seit', 'Letzte Aktivität', '']">
    <tbody class="divide-y divide-gray-100">
        @foreach ($sessions as $session)
            <tr class="hover:bg-gray-50">
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
                <td class="px-4 py-2 text-right">
                    @unless ($session->session_id === $currentSessionId)
                        <x-confirm-form :action="route('profile.sessions.destroy', $session)" message="Diese Sitzung beenden?" label="Beenden" size="sm" />
                    @endunless
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
@endsection
