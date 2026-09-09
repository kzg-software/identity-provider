@extends('layouts.auth-card')

@section('auth-content')
<h1 class="mb-1 text-lg font-semibold text-gray-900">Passwort vergessen</h1>
<p class="mb-4 text-sm text-gray-600">
    Gib die E-Mail-Adresse deines Kontos ein. Wir senden dir einen Link zum Zurücksetzen.
    Das gilt nur für lokale Konten, Verzeichniskonten werden über das Verzeichnis verwaltet.
</p>

<form method="POST" action="{{ route('password.email') }}" class="space-y-4">
    @csrf
    <div>
        <x-input-label value="E-Mail-Adresse" />
        <x-input type="email" name="email" value="{{ old('email') }}" required autofocus
                 placeholder="du@firma.de" :error="$errors->first('email')" />
    </div>
    <x-button type="submit" class="w-full">Link senden</x-button>
</form>

<p class="mt-4 text-center text-sm">
    <a href="{{ route('login') }}" class="text-laravel-600 hover:text-laravel-700">Zurück zur Anmeldung</a>
</p>
@endsection
