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
