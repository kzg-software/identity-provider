@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="OIDC-Schlüssel"
    description="Mit diesen Schlüsseln signiert das System die ID-Tokens für OpenID Connect. Der öffentliche Teil steht unter der JWKS-URL, private Schlüssel werden verschlüsselt gespeichert.">
    <x-slot:actions>
        <x-confirm-form :action="route('admin.oidc-keys.rotate')" method="POST"
                        variant="primary" icon="arrow-path" size="sm"
                        title="Schlüssel rotieren"
                        message="Ein neuer Signaturschlüssel wird erzeugt und sofort aktiv. Bereits ausgestellte Tokens bleiben bis zum Ablauf über die JWKS-URL prüfbar."
                        label="Schlüssel rotieren" />
    </x-slot:actions>
</x-page-header>

<x-card class="mb-4" :padding="true">
    <x-dl mono :rows="['JWKS-URL' => url('/.well-known/jwks.json')]" />
</x-card>

<x-table :heads="['Kid', 'Algorithmus', 'Bits', 'Fingerprint', 'Erstellt', 'Läuft ab', 'Status']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($keys as $entry)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $entry['model']->kid }}</code></td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['model']->algorithm }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['bits'] }}</td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $entry['fingerprint'] }}</code></td>
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
        @empty
            <x-empty-state cell :colspan="7" icon="key" title="Noch kein Schlüssel">
                Beim ersten OpenID-Connect-Login wird automatisch einer erzeugt. Du kannst auch jetzt schon einen anlegen.
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
