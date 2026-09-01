@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Schritt 4: Lokaler Administrator</h2>
<p class="text-sm text-gray-500 mb-4">Dieser Benutzer wird als lokales Konto (<code class="bg-gray-100 px-1 rounded">local</code>) angelegt und dient als Break-Glass-Administrator.</p>

<form method="POST" action="{{ route('install.admin.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Benutzername" />
        <x-input type="text" name="username" value="{{ old('username') }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Vorname" />
            <x-input type="text" name="first_name" value="{{ old('first_name') }}" required />
        </div>
        <div>
            <x-input-label value="Nachname" />
            <x-input type="text" name="last_name" value="{{ old('last_name') }}" required />
        </div>
    </div>

    <div>
        <x-input-label value="E-Mail-Adresse" />
        <x-input type="email" name="email" value="{{ old('email') }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Passwort" />
            <x-input type="password" name="password" required />
        </div>
        <div>
            <x-input-label value="Passwort bestätigen" />
            <x-input type="password" name="password_confirmation" required />
        </div>
    </div>

    <x-button type="submit">Weiter</x-button>
</form>
@endsection
