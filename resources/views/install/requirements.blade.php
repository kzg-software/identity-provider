@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-4">Schritt 1: Systemprüfung</h2>

<ul class="divide-y divide-gray-200 border border-gray-200 rounded-md mb-6">
    @foreach ($checks as $check)
        <li class="flex justify-between items-center px-4 py-3 text-sm">
            <span class="text-gray-700">{{ $check['label'] }}</span>
            <x-badge :color="$check['ok'] ? 'green' : 'red'">
                {{ $check['ok'] ? 'OK' : 'Fehler' }} — {{ $check['detail'] }}
            </x-badge>
        </li>
    @endforeach
</ul>

<form method="POST" action="{{ route('install.requirements.continue') }}">
    @csrf
    <x-button type="submit">Weiter</x-button>
</form>
@endsection
