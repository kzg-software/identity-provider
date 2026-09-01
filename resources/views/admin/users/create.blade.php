@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Lokalen Benutzer anlegen</h1>

<x-card class="max-w-lg">
    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-4">
        @csrf
        <div>
            <x-input-label value="Benutzername" />
            <x-input type="text" name="username" required />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <x-input-label value="Vorname" />
                <x-input type="text" name="first_name" required />
            </div>
            <div>
                <x-input-label value="Nachname" />
                <x-input type="text" name="last_name" required />
            </div>
        </div>
        <div>
            <x-input-label value="E-Mail" />
            <x-input type="email" name="email" required />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <x-input-label value="Passwort" />
                <x-input type="password" name="password" required />
            </div>
            <div>
                <x-input-label value="Bestätigen" />
                <x-input type="password" name="password_confirmation" required />
            </div>
        </div>
        <label class="flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer select-none">
            <x-checkbox name="is_admin" value="1" />
            Administrator
        </label>
        <x-button type="submit">Anlegen</x-button>
    </form>
</x-card>
@endsection
