@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Anwendungen"
    description="Programme, die im Benutzerportal erscheinen und sich über dieses System anmelden. Die technische SSO-Konfiguration liegt getrennt unter „Provider“.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.applications.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Anwendung anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Name', 'Provider', 'Sichtbarkeit', 'Status', '']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($applications as $application)
            @php $provider = $application->provider; @endphp
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <div class="flex items-center gap-3">
                        @if ($application->logo_path)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($application->logo_path) }}" alt="" class="h-8 w-8 rounded object-contain bg-gray-50">
                        @else
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-laravel-50 text-laravel-600">
                                <x-icon name="building" class="h-4 w-4" />
                            </span>
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('admin.applications.show', $application) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $application->name }}</a>
                            @if ($application->description)
                                <p class="truncate text-xs text-gray-500">{{ $application->description }}</p>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="px-4 py-2">
                    @if ($provider)
                        <a href="{{ route('admin.providers.show', $provider) }}" class="inline-flex items-center gap-2 text-sm text-gray-700 hover:text-laravel-700">
                            <x-provider-badge :provider="$provider" />
                            <span class="text-gray-500">{{ $provider->name }}</span>
                        </a>
                    @else
                        <x-provider-badge />
                    @endif
                </td>
                <td class="px-4 py-2 text-gray-600">
                    {{ $application->isVisibleInPortal() ? 'Portal' : 'Verborgen' }}
                </td>
                <td class="px-4 py-2">
                    @if ($application->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge>inaktiv</x-badge>
                    @endif
                    @if ($application->maintenance_mode)<x-badge color="amber">Wartung</x-badge>@endif
                </td>
                <td class="px-4 py-2 text-right">
                    <x-button tag="a" href="{{ route('admin.applications.show', $application) }}" variant="secondary" size="sm">Verwalten</x-button>
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="5" icon="building" title="Noch keine Anwendung angelegt">
                Lege eine Anwendung an – wahlweise nur für das Portal oder direkt mit einem OAuth-/OIDC- oder SAML-Provider.
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
