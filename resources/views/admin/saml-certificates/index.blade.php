@extends('layouts.admin')

@section('admin-content')
<div class="flex justify-between items-center mb-2">
    <h1 class="text-2xl font-semibold text-gray-900">SAML-Signaturzertifikate</h1>
    <form method="POST" action="{{ route('admin.saml-certificates.rotate') }}" x-data @submit="if (!confirm('Neues Zertifikat erzeugen und aktivieren?')) $event.preventDefault()">
        @csrf
        <x-button type="submit">Zertifikat rotieren</x-button>
    </form>
</div>

<p class="text-sm text-gray-500 mb-6">Veröffentlicht unter <code class="bg-gray-100 px-1 rounded">{{ url('/saml/metadata') }}</code>. Private Keys werden verschlüsselt gespeichert und nie ausgegeben.</p>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Algorithmus</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Fingerprint</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Ausgestellt</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Läuft ab</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($certificates as $entry)
            <tr>
                <td class="px-4 py-2 text-gray-900">{{ $entry['model']->name }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['model']->algorithm }}</td>
                <td class="px-4 py-2"><code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ $entry['model']->fingerprint }}</code></td>
                <td class="px-4 py-2 text-gray-500">{{ $entry['model']->issued_at?->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2 text-gray-500">
                    {{ $entry['model']->expires_at?->format('d.m.Y') }}
                    @if ($entry['expiring_soon'])
                        <x-badge color="amber" class="ml-1">läuft bald ab</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2">
                    @if ($entry['model']->is_active)
                        <x-badge color="green">aktiv (signiert)</x-badge>
                    @else
                        <x-badge>nur historisch</x-badge>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-3 text-gray-400">Noch kein Zertifikat erzeugt — wird beim ersten Login automatisch erstellt.</td></tr>
        @endforelse
    </tbody>
</x-table>
@endsection
