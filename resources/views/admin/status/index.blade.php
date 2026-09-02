@extends('layouts.admin')

@section('admin-content')
@php
    $counts = collect($checks)->countBy('status');
    $failed = $counts['fail'] ?? 0;
    $warned = $counts['warn'] ?? 0;
@endphp

<x-page-header
    title="Systemstatus"
    description="Automatische Prüfungen von Konfiguration, Schlüsseln, Verzeichnissen und Hintergrundjobs. Nach jeder Änderung ein guter erster Blick." />

@if ($failed > 0)
    <x-alert type="danger">{{ $failed }} {{ $failed === 1 ? 'Prüfung meldet' : 'Prüfungen melden' }} einen Fehler. Details stehen in der Tabelle.</x-alert>
@elseif ($warned > 0)
    <x-alert type="warning">{{ $warned }} {{ $warned === 1 ? 'Prüfung hat' : 'Prüfungen haben' }} einen Hinweis. Alles Wichtige läuft.</x-alert>
@else
    <x-alert type="success">Alle Prüfungen sind grün.</x-alert>
@endif

<x-table :heads="['Prüfung', 'Status', 'Detail']">
    <tbody class="divide-y divide-gray-100">
        @foreach ($checks as $check)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-900">{{ $check['label'] }}</td>
                <td class="px-4 py-2">
                    @if ($check['status'] === 'ok')
                        <x-badge color="green">OK</x-badge>
                    @elseif ($check['status'] === 'warn')
                        <x-badge color="amber">Hinweis</x-badge>
                    @else
                        <x-badge color="red">Fehler</x-badge>
                    @endif
                </td>
                <td class="px-4 py-2 text-xs text-gray-500">{{ $check['detail'] }}</td>
            </tr>
        @endforeach
    </tbody>
</x-table>
@endsection
