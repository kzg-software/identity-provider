@extends('layouts.auth-card')

@php
    $spinner = '<svg x-show="loading" x-cloak class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
@endphp

@section('auth-content')
@php
    $initialTab = $errors->has('recovery_code') ? 'recovery'
        : ($errors->has('code') ? 'totp'
        : ($hasWebauthn ? 'webauthn' : ($hasTotp ? 'totp' : 'recovery')));
@endphp
<div x-data="{ tab: '{{ $initialTab }}', loading: false, trust: false }"
     x-on:pageshow.window="loading = false">
    <h1 class="text-lg font-semibold text-gray-900 mb-1">Zwei-Faktor-Bestätigung</h1>
    <p class="text-sm text-gray-500 mb-4">Bestätige die Anmeldung mit deinem zweiten Faktor.</p>

    @if ($hasWebauthn)
        <div x-show="tab === 'webauthn'" class="space-y-3">
            <p class="text-sm text-gray-600">Verwende deinen Passkey (Sicherheitsschlüssel, Fingerabdruck oder Geräte-PIN).</p>
            <x-button type="button" class="w-full" id="twoFactorPasskeyButton"
                      x-bind:disabled="loading" x-bind:aria-busy="loading">
                {!! $spinner !!}
                <x-icon name="key" class="h-4 w-4" x-show="! loading" />
                <span x-text="loading ? 'Wird bestätigt …' : 'Mit Passkey bestätigen'">Mit Passkey bestätigen</span>
            </x-button>
            <p id="twoFactorPasskeyError" class="hidden text-sm text-red-600"></p>
        </div>
    @endif

    @if ($hasTotp)
        <div x-show="tab === 'totp'" class="space-y-3">
            <form method="POST" action="{{ route('two-factor.totp') }}" class="space-y-3" @submit="loading = true">
                @csrf
                <input type="hidden" name="trust_device" :value="trust ? '1' : '0'">
                <div>
                    <x-input-label value="Code aus der Authenticator-App" />
                    <x-input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                             pattern="[0-9 ]*" required autofocus placeholder="123456" x-bind:readonly="loading" />
                </div>
                <x-button type="submit" class="w-full" x-bind:disabled="loading" x-bind:aria-busy="loading">
                    {!! $spinner !!}
                    <span x-text="loading ? 'Anmeldung läuft …' : 'Bestätigen'">Bestätigen</span>
                </x-button>
            </form>
        </div>
    @endif

    <div x-show="tab === 'recovery'" class="space-y-3">
        <form method="POST" action="{{ route('two-factor.recovery') }}" class="space-y-3" @submit="loading = true">
            @csrf
            <input type="hidden" name="trust_device" :value="trust ? '1' : '0'">
            <div>
                <x-input-label value="Wiederherstellungscode" />
                <x-input type="text" name="recovery_code" required placeholder="XXXXX-XXXXX"
                         autocomplete="one-time-code" x-bind:readonly="loading" />
            </div>
            <x-button type="submit" class="w-full" x-bind:disabled="loading" x-bind:aria-busy="loading">
                {!! $spinner !!}
                <span x-text="loading ? 'Anmeldung läuft …' : 'Anmelden'">Anmelden</span>
            </x-button>
        </form>
    </div>

    @if ($canTrustDevice)
        <label class="mt-4 flex items-start gap-2 text-sm text-gray-600">
            <input type="checkbox" x-model="trust"
                   class="mt-0.5 h-4 w-4 rounded border-gray-300 text-laravel-600 focus:ring-laravel-500">
            <span>Diesem Gerät vertrauen und {{ $trustDeviceDays }} Tage nicht mehr nach dem zweiten Faktor fragen. Nur auf privaten Geräten wählen.</span>
        </label>
    @endif

    <div class="mt-5 pt-4 border-t border-gray-200 text-sm flex flex-wrap gap-x-4 gap-y-1">
        @if ($hasWebauthn)
            <button type="button" class="text-laravel-600 hover:text-laravel-700" x-show="tab !== 'webauthn'" @click="tab = 'webauthn'">Passkey verwenden</button>
        @endif
        @if ($hasTotp)
            <button type="button" class="text-laravel-600 hover:text-laravel-700" x-show="tab !== 'totp'" @click="tab = 'totp'">Authenticator-App verwenden</button>
        @endif
        <button type="button" class="text-laravel-600 hover:text-laravel-700" x-show="tab !== 'recovery'" @click="tab = 'recovery'">Wiederherstellungscode verwenden</button>
    </div>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-3">
        @csrf
        <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Abbrechen und neu anmelden</button>
    </form>
</div>

@if ($hasWebauthn)
@push('scripts')
<script src="{{ asset('vendor/webauthn/webauthn.js') }}"></script>
<script>
    (function () {
        var btn = document.getElementById('twoFactorPasskeyButton');
        var err = document.getElementById('twoFactorPasskeyError');
        if (!btn) { return; }
        if (!window.Webauthn || !window.Webauthn.supported()) { btn.disabled = true; return; }

        var root = btn.closest('[x-data]');
        var setLoading = function (on) {
            try { window.Alpine.$data(root).loading = on; } catch (_) { btn.disabled = on; }
        };

        btn.addEventListener('click', function () {
            err.classList.add('hidden');
            setLoading(true);

            var trust = false;
            try { trust = !! window.Alpine.$data(root).trust; } catch (_) {}

            window.Webauthn.authenticate(
                '{{ route('two-factor.webauthn.options') }}',
                '{{ route('two-factor.webauthn') }}',
                '{{ csrf_token() }}',
                { trust_device: trust ? '1' : '0' }
            ).then(function (res) {
                if (res.redirected) { window.location.href = res.url; return; }
                if (res.ok) { window.location.href = '{{ route('dashboard') }}'; return; }
                return res.json().then(function (data) {
                    throw new Error(data && data.message ? data.message : 'failed');
                });
            }).catch(function (e) {
                setLoading(false);
                err.textContent = (e && e.message && e.name !== 'NotAllowedError' && e.name !== 'AbortError')
                    ? e.message
                    : 'Die Bestätigung mit dem Passkey wurde abgebrochen oder ist fehlgeschlagen.';
                err.classList.remove('hidden');
            });
        });
    })();
</script>
@endpush
@endif
@endsection
