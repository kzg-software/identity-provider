@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">System</h2>
<p class="text-sm text-gray-500 mb-5">Name, Adresse und Aussehen des Portals. Alles hier lässt sich später in der Administration ändern.</p>

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
            <x-select name="locale" required>
                @foreach (\App\Support\Locales::available() as $code => $name)
                    <option value="{{ $code }}" @selected(old('locale', 'de') === $code)>{{ $name }}</option>
                @endforeach
            </x-select>
        </div>
    </div>

    <div>
        <x-input-label value="Automatische Abmeldung nach (Minuten)" />
        <x-input type="number" name="session_lifetime" value="{{ old('session_lifetime', 120) }}" required />
        <p class="mt-1 text-xs text-gray-500">Wie lange ein Benutzer ohne Aktivität angemeldet bleibt.</p>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Logo" />
            <x-image-upload-input name="logo" />
        </div>
        <div>
            <x-input-label value="Favicon" />
            <x-image-upload-input name="favicon" accept="image/*,.ico,image/x-icon,image/vnd.microsoft.icon" preview-class="h-10 w-10" />
        </div>
    </div>

    <hr class="border-gray-200">
    <h3 class="text-sm font-semibold text-gray-700">E-Mail-Versand (optional)</h3>
    <p class="text-xs text-gray-500">Nötig, damit das System Benachrichtigungen verschicken kann. Kann leer bleiben und später nachgetragen werden.</p>
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
