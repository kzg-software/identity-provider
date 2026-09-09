@extends('layouts.admin')

@section('admin-content')
<div class="max-w-lg space-y-6">
    <x-page-header title="Authenticator-App einrichten" :back="route('profile.security')" back-label="Sicherheit"
                   description="Scanne den Code mit deiner Authenticator-App und gib den erzeugten Code ein." />

    <x-card>
        <div class="flex flex-col items-center gap-4">
            <div class="rounded-md border border-gray-200 bg-white p-3">{!! $qrSvg !!}</div>
            <div class="text-center">
                <p class="text-xs text-gray-500 mb-1">Falls das Scannen nicht klappt, den Schlüssel manuell eintragen:</p>
                <code class="text-sm font-mono break-all">{{ $secret }}</code>
            </div>
        </div>

        <form method="POST" action="{{ route('profile.security.totp.store') }}" class="mt-6 space-y-3">
            @csrf
            <div>
                <x-input-label value="Code aus der App" />
                <x-input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                         pattern="[0-9 ]*" required autofocus placeholder="123456" :error="$errors->first('code')" />
            </div>
            <div class="flex gap-3">
                <x-button type="submit">Aktivieren</x-button>
                <x-button tag="a" href="{{ route('profile.security') }}" variant="secondary">Abbrechen</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
