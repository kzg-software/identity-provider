@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="SAML-Zertifikate"
    description="Mit diesen Zertifikaten signiert das System die SAML-Antworten. Der öffentliche Teil steht in den IdP-Metadaten, private Schlüssel werden verschlüsselt gespeichert.">
    <x-slot:actions>
        <x-confirm-form :action="route('admin.saml-certificates.rotate')" method="POST"
                        variant="primary" icon="arrow-path" size="sm"
                        title="Zertifikat rotieren"
                        message="Ein neues Signaturzertifikat wird erzeugt und sofort aktiv. Das bisherige bleibt für die Prüfung älterer Signaturen erhalten."
                        label="Zertifikat rotieren" />
    </x-slot:actions>
</x-page-header>

<x-card class="mb-4" :padding="true">
    <x-dl mono :rows="['Metadaten-URL' => url('/saml/metadata')]" />
</x-card>

<x-table :heads="['Name', 'Algorithmus', 'Fingerprint', 'Ausgestellt', 'Läuft ab', 'Status']">
    <tbody class="divide-y divide-gray-100">
        @forelse ($certificates as $entry)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-900">{{ $entry['model']->name }}</td>
                <td class="px-4 py-2 text-gray-600">{{ $entry['model']->algorithm }}</td>
                <td class="px-4 py-2"><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $entry['model']->fingerprint }}</code></td>
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
            <x-empty-state cell :colspan="6" icon="lock-closed" title="Noch kein Zertifikat">
                Beim ersten SAML-Login wird automatisch eines erzeugt. Du kannst auch jetzt schon eines anlegen.
            </x-empty-state>
        @endforelse
    </tbody>
</x-table>
@endsection
