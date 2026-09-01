@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-4">Schritt 3: System</h2>

<form method="POST" action="{{ route('install.system.store') }}" enctype="multipart/form-data" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Systemname" />
        <x-input type="text" name="system_name" value="{{ old('system_name', 'Auth Portal') }}" required />
    </div>

    <div>
        <x-input-label value="Basis-URL" />
        <x-input type="url" name="base_url" value="{{ old('base_url', url('/')) }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Zeitzone" />
            <x-input type="text" name="timezone" value="{{ old('timezone', 'Europe/Berlin') }}" required />
        </div>
        <div>
            <x-input-label value="Sprache" />
            <x-input type="text" name="locale" value="{{ old('locale', 'de') }}" required />
        </div>
    </div>

    <div>
        <x-input-label value="Session-Dauer (Minuten)" />
        <x-input type="number" name="session_lifetime" value="{{ old('session_lifetime', 120) }}" required />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Logo" />
            <input type="file" name="logo" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
        </div>
        <div>
            <x-input-label value="Favicon" />
            <input type="file" name="favicon" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
        </div>
    </div>

    <hr class="border-gray-200">
    <h3 class="text-sm font-semibold text-gray-700">E-Mail-Konfiguration (optional)</h3>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="SMTP-Host" />
            <x-input type="text" name="mail_host" value="{{ old('mail_host') }}" />
        </div>
        <div>
            <x-input-label value="SMTP-Port" />
            <x-input type="number" name="mail_port" value="{{ old('mail_port') }}" />
        </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Benutzer" />
            <x-input type="text" name="mail_username" value="{{ old('mail_username') }}" />
        </div>
        <div>
            <x-input-label value="Passwort" />
            <x-input type="password" name="mail_password" value="{{ old('mail_password') }}" />
        </div>
    </div>
    <div>
        <x-input-label value="Absenderadresse" />
        <x-input type="email" name="mail_from_address" value="{{ old('mail_from_address') }}" />
    </div>

    <x-button type="submit">Weiter</x-button>
</form>
@endsection
