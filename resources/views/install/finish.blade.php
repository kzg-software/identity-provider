@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-3">Abschluss</h2>

<p class="text-sm text-gray-600 mb-2">Alle nötigen Angaben sind erfasst. Mit dem Klick auf die Schaltfläche wird die Einrichtung abgeschlossen.</p>
<p class="text-sm text-gray-600 mb-5">Danach ist der Einrichtungs-Assistent gesperrt und nicht mehr aufrufbar. Sie landen auf der normalen Anmeldeseite und melden sich mit dem eben angelegten Administrator-Konto an.</p>

<form method="POST" action="{{ route('install.complete') }}">
    @csrf
    <x-button type="submit" variant="success">Einrichtung abschließen</x-button>
</form>
@endsection
