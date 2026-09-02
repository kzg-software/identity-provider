@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Verzeichnis anlegen"
    :back="route('admin.directories.index')" back-label="Alle Verzeichnisse"
    description="Verbinde Active Directory oder LDAP. Danach holt das System Benutzer und Gruppen von dort und prüft Anmeldungen." />

<x-card>
    <form method="POST" action="{{ route('admin.directories.store') }}">
        @csrf
        @include('admin.directories._form')
        <div class="mt-6 flex gap-3 border-t border-gray-100 pt-6">
            <x-button type="submit">Speichern</x-button>
            <x-button tag="a" href="{{ route('admin.directories.index') }}" variant="link">Abbrechen</x-button>
        </div>
    </form>
</x-card>
@endsection
