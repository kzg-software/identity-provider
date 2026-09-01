@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Verzeichnis anlegen</h1>

<x-card>
    <form method="POST" action="{{ route('admin.directories.store') }}">
        @csrf
        @include('admin.directories._form')
        <div class="flex gap-3 mt-6">
            <x-button type="submit">Speichern</x-button>
            <x-button tag="a" href="{{ route('admin.directories.index') }}" variant="link">Abbrechen</x-button>
        </div>
    </form>
</x-card>
@endsection
