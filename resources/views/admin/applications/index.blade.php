@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Anwendungen"
    description="Programme, die sich per OAuth 2.0 oder OpenID Connect an diesem System anmelden lassen.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.applications.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Anwendung anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Name', 'Client ID', 'Anmeldung', 'Zustimmung', 'Status', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($applications as $application)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <a href="{{ route('admin.applications.show', $application) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $application->name }}</a>
                </td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $application->oauthClients->first()?->client_id }}</code></td>
                <td class="px-4 py-2 text-gray-600">{{ $application->login_mode }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $application->consent_required ? $application->consent_mode : 'nicht erforderlich' }}</td>
                <td class="px-4 py-2">
                    @if ($application->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge>inaktiv</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2 text-right">
                    <x-button tag="a" href="{{ route('admin.applications.show', $application) }}" variant="secondary" size="sm">Verwalten</x-button>
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="6" icon="building" title="Noch keine Anwendung angelegt">
                Lege eine Anwendung an, um einem Programm die Anmeldung über dieses System zu erlauben.
                <x-slot:action>
                    <x-button tag="a" href="{{ route('admin.applications.create') }}" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />Anwendung anlegen
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
