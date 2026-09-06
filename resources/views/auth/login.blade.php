@extends('layouts.auth-card')

@section('auth-content')
<div id="autoLoginNotice" class="hidden mb-4 rounded-md bg-gray-50 border border-gray-200 px-4 py-3 text-sm text-gray-600 flex items-center gap-2">
    <svg class="animate-spin h-4 w-4 text-laravel-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
    Automatische Anmeldung wird versucht &hellip;
</div>

<form method="POST" action="{{ route('login.attempt') }}" class="space-y-4"
      x-data="{ loading: false }" @submit="loading = true" x-on:pageshow.window="loading = false">
    @csrf
    <div>
        <x-input-label value="Benutzername" />
        <x-input type="text" name="username" value="{{ old('username') }}" required autofocus placeholder="mmustermann" x-bind:readonly="loading" />
    </div>
    <div>
        <x-input-label value="Passwort" />
        <x-input type="password" name="password" required x-bind:readonly="loading" />
    </div>
    <x-button type="submit" class="w-full" x-bind:disabled="loading" x-bind:aria-busy="loading">
        <svg x-show="loading" x-cloak class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span x-text="loading ? 'Anmeldung läuft …' : 'Anmelden'">Anmelden</span>
    </x-button>
</form>

@if (\App\Support\SecuritySettings::passwordlessAnyEnabled())
<div id="passkeyLoginWrap" class="hidden mt-4" x-data="{ loading: false }" x-on:pageshow.window="loading = false">
    <div class="relative my-4">
        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
        <div class="relative flex justify-center text-xs"><span class="bg-white px-2 text-gray-400">oder</span></div>
    </div>
    <x-button type="button" variant="secondary" class="w-full" id="passkeyLoginButton"
              x-bind:disabled="loading" x-bind:aria-busy="loading">
        <svg x-show="loading" x-cloak class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <x-icon name="key" class="h-4 w-4" x-show="! loading" />
        <span x-text="loading ? 'Anmeldung läuft …' : 'Mit Passkey anmelden'">Mit Passkey anmelden</span>
    </x-button>
    <p id="passkeyLoginError" class="hidden mt-2 text-sm text-red-600 text-center"></p>
</div>
@push('scripts')
<script src="{{ asset('vendor/webauthn/webauthn.js') }}"></script>
<script>
    (function () {
        var wrap = document.getElementById('passkeyLoginWrap');
        var btn = document.getElementById('passkeyLoginButton');
        var err = document.getElementById('passkeyLoginError');
        if (!wrap || !window.Webauthn || !window.Webauthn.supported()) { return; }
        wrap.classList.remove('hidden');

        var setLoading = function (on) {
            try { window.Alpine.$data(wrap).loading = on; } catch (_) { btn.disabled = on; }
        };

        btn.addEventListener('click', function () {
            err.classList.add('hidden');
            setLoading(true);
            window.Webauthn.authenticate(
                '{{ route('login.passkey.options') }}',
                '{{ route('login.passkey.verify') }}',
                '{{ csrf_token() }}'
            ).then(function (res) {
                if (res.redirected) { window.location.href = res.url; return; }
                if (res.ok) { window.location.href = '{{ route('dashboard') }}'; return; }
                return res.json().then(function (data) {
                    var msg = data && data.errors && data.errors.username ? data.errors.username[0]
                        : (data && data.message ? data.message : 'Die Anmeldung mit dem Passkey ist fehlgeschlagen.');
                    throw new Error(msg);
                });
            }).catch(function (e) {
                setLoading(false);
                err.textContent = (e && e.message && e.name !== 'NotAllowedError' && e.name !== 'AbortError')
                    ? e.message
                    : 'Es wurde kein passender Passkey gefunden oder der Vorgang wurde abgebrochen.';
                err.classList.remove('hidden');
            });
        });
    })();
</script>
@endpush
@endif

@if (! request()->boolean('manual') && ! $errors->any() && \App\Models\SystemSetting::windowsSsoEnabled())
<script>
    (function () {
        var notice = document.getElementById('autoLoginNotice');
        notice.classList.remove('hidden');

        fetch('{{ route('auth.negotiate') }}', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (res) {
            if (res.ok) {
                return res.json().then(function (data) {
                    if (data && data.success) {
                        window.location.href = data.redirect || '{{ route('dashboard') }}';
                        return;
                    }
                    notice.classList.add('hidden');
                });
            }
            notice.classList.add('hidden');
        }).catch(function () {
            notice.classList.add('hidden');
        });
    })();
</script>
@endif
@endsection
