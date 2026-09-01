@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Systemstatus</h1>

<x-table>
    <thead class="bg-gray-50">
        <tr>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Prüfung</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
            <th class="px-4 py-2 text-left font-medium text-gray-500">Detail</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @foreach ($checks as $check)
            <tr>
                <td class="px-4 py-2 text-gray-900">{{ $check['label'] }}</td>
                <td class="px-4 py-2">
                    @if ($check['status'] === 'ok')
                        <x-badge color="green">OK</x-badge>
                    @elseif ($check['status'] === 'warn')
                        <x-badge color="amber">Warnung</x-badge>
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
