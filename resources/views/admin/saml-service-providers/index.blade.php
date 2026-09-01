@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">SAML Service Provider</h1>
    <x-button tag="a" href="{{ route('admin.saml-service-providers.create') }}"><x-icon name="plus" class="h-4 w-4" />Neuer Service Provider</x-button>
</div>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Entity ID</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">ACS URL</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">NameID-Format</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($providers as $sp)
            <tr>
                <td class="px-4 py-2"><a href="{{ route('admin.saml-service-providers.show', $sp) }}" class="text-laravel-600 hover:text-laravel-700 font-medium">{{ $sp->name }}</a></td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $sp->entity_id }}</code></td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $sp->acs_url }}</code></td>
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
            <tr><td colspan="5" class="px-4 py-3 text-gray-400">Noch keine SAML Service Provider angelegt.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
