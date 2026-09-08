@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Provider"
    description="Technische Authentifizierungs- und SSO-Konfigurationen (OAuth 2.0 / OpenID Connect und SAML 2.0). Ein Provider wird einer Anwendung zugeordnet.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.providers.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Provider anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Name', 'Typ', 'Anwendung', 'Client-ID / Entity-ID', 'Status', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($providers as $provider)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <a href="{{ route('admin.providers.show', $provider) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $provider->name }}</a>
                </td>
                <td class="px-4 py-2"><x-provider-badge :provider="$provider" /></td>
                <td class="px-4 py-2 text-sm text-gray-600">
                    @forelse ($provider->applications as $app)
                        <a href="{{ route('admin.applications.show', $app) }}" class="text-laravel-600 hover:text-laravel-700">{{ $app->name }}</a>@if (! $loop->last), @endif
                    @empty
                        <span class="text-gray-400">— nicht zugeordnet</span>
                    @endforelse
                </td>
                <td class="px-4 py-2">
                    <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $provider->isOidc() ? $provider->oauthClient?->client_id : $provider->samlServiceProvider?->entity_id }}</code>
                </td>
                <td class="px-4 py-2">
                    @if ($provider->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge>inaktiv</x-badge>
                    @endif
                    @unless ($provider->isConfigured())<x-badge color="amber">unvollständig</x-badge>@endunless
                </td>
                <td class="px-4 py-2 text-right">
                    <x-button tag="a" href="{{ route('admin.providers.show', $provider) }}" variant="secondary" size="sm">Verwalten</x-button>
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="6" icon="key" title="Noch kein Provider angelegt">
                Lege einen OAuth-/OIDC- oder SAML-Provider an und verknüpfe ihn mit einer Anwendung.
                <x-slot:action>
                    <x-button tag="a" href="{{ route('admin.providers.create') }}" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />Provider anlegen
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
