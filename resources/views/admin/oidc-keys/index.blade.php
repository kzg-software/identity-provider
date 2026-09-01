@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-2">
    <h1 class="text-2xl font-semibold text-gray-900">OIDC-Signaturschlüssel</h1>
    <form method="POST" action="{{ route('admin.oidc-keys.rotate') }}" x-data @submit="if (!confirm('Neuen Schlüssel erzeugen und aktivieren? Alte Tokens bleiben bis zum Ablauf über JWKS prüfbar.')) $event.preventDefault()">
        @csrf
        <x-button type="submit">Schlüssel rotieren</x-button>
    </form>
</div>

<p class="text-sm text-gray-500 mb-6">Veröffentlicht unter <code class="bg-gray-100 px-1 rounded">{{ url('/.well-known/jwks.json') }}</code>. Private Keys werden verschlüsselt gespeichert und nie ausgegeben.</p>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Kid</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Algorithmus</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Bits</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Fingerprint</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Erstellt</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Läuft ab</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @foreach ($keys as $entry)
            <tr>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $entry['model']->kid }}</code></td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['model']->algorithm }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['bits'] }}</td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $entry['fingerprint'] }}</code></td>
                <td class="px-4 py-2 text-gray-500">{{ $entry['model']->created_at->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2 text-gray-500">
                    {{ $entry['expires_at']->format('d.m.Y') }}
                    @if ($entry['expiring_soon'])
                        <x-badge color="amber" class="ml-1">läuft bald ab</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2">
                    @if ($entry['model']->is_active)
                        <x-badge color="green">aktiv (signiert)</x-badge>
                    @else
                        <x-badge>nur Verifizierung</x-badge>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</x-table>
@endsection
