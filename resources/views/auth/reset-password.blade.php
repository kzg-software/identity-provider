@extends('layouts.auth-card')

@section('auth-content')
<h1 class="mb-1 text-lg font-semibold text-gray-900">Neues Passwort setzen</h1>
<p class="mb-4 text-sm text-gray-600">Wähle ein neues Passwort für dein Konto.</p>

<form method="POST" action="{{ route('password.update') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div>
        <x-input-label value="E-Mail-Adresse" />
        <x-input type="email" name="email" value="{{ old('email', $email) }}" required
                 :error="$errors->first('email')" />
    </div>
    <div>
        <x-input-label value="Neues Passwort" />
        <x-input type="password" name="password" autocomplete="new-password" required
                 :error="$errors->first('password')" />
        <p class="mt-1 text-xs text-gray-500">{{ \App\Support\SecuritySettings::passwordHint() }}</p>
    </div>
    <div>
        <x-input-label value="Neues Passwort wiederholen" />
        <x-input type="password" name="password_confirmation" autocomplete="new-password" required />
    </div>
    <x-button type="submit" class="w-full">Passwort speichern</x-button>
</form>

<p class="mt-4 text-center text-sm">
    <a href="{{ route('login') }}" class="text-laravel-600 hover:text-laravel-700">Zurück zur Anmeldung</a>
</p>
@endsection
