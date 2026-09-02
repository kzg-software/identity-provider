@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="SAML Service Provider"
    description="Anwendungen, die sich per SAML 2.0 an diesem System anmelden. Dieses System ist dabei der Identity Provider.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.saml-service-providers.create') }}" size="sm">
            <x-icon name="plus" class="h-4 w-4" />Service Provider anlegen
        </x-button>
    </x-slot:actions>
</x-page-header>

<x-table :heads="['Name', 'Entity ID', 'ACS URL', 'NameID-Format', 'Status']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($providers as $sp)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2">
                    <a href="{{ route('admin.saml-service-providers.show', $sp) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $sp->name }}</a>
                </td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $sp->entity_id }}</code></td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $sp->acs_url }}</code></td>
                <td class="px-4 py-2 text-xs text-gray-600">{{ Str::afterLast($sp->name_id_format, ':') }}</td>
                <td class="px-4 py-2">
                    @if ($sp->is_active)
                        <x-badge color="green">aktiv</x-badge>
                    @else
                        <x-badge>inaktiv</x-badge>
                    @endif
                </td>
            </tr>
        @empty
            <x-empty-state cell :colspan="5" icon="shield-check" title="Noch kein Service Provider angelegt">
                Lege einen Service Provider an, um einer SAML-Anwendung die Anmeldung über dieses System zu erlauben.
                <x-slot:action>
                    <x-button tag="a" href="{{ route('admin.saml-service-providers.create') }}" size="sm">
                        <x-icon name="plus" class="h-4 w-4" />Service Provider anlegen
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
