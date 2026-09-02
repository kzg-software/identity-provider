@extends('layouts.admin')

@section('admin-content')
<x-page-header
    :title="'Verzeichnis bearbeiten: '.$directory->name"
    :back="route('admin.directories.show', $directory)" back-label="Zurück zur Übersicht"
    description="Änderungen greifen ab der nächsten Anmeldung und der nächsten Synchronisierung." />

<x-card>
    <form method="POST" action="{{ route('admin.directories.update', $directory) }}">
        @csrf
        @method('PUT')
        @include('admin.directories._form')
        <div class="mt-6 flex gap-3 border-t border-gray-100 pt-6">
            <x-button type="submit">Speichern</x-button>
            <x-button tag="a" href="{{ route('admin.directories.show', $directory) }}" variant="link">Abbrechen</x-button>
        </div>
    </form>
</x-card>
@endsection
