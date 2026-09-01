@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Anwendungen</h1>
    <x-button tag="a" href="{{ route('admin.applications.create') }}"><x-icon name="plus" class="h-4 w-4" />Neue Anwendung</x-button>
</div>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Client ID</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Login-Modus</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Consent</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
            <th class="px-4 py-2"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($applications as $application)
            <tr>
                <td class="px-4 py-2 text-gray-900 font-medium">{{ $application->name }}</td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $application->oauthClients->first()?->client_id }}</code></td>
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
            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Noch keine Anwendungen angelegt.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
