@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-3">Schritt 7: Abschluss</h2>

<p class="text-sm text-gray-600 mb-4">Die Installation ist bereit zum Abschluss. Danach wird der Installer gesperrt und ist öffentlich nicht mehr erreichbar.</p>

<form method="POST" action="{{ route('install.complete') }}">
    @csrf
    <x-button type="submit" variant="success">Installation abschließen</x-button>
</form>
@endsection
