@extends('layouts.admin')

@section('admin-content')
@php($hasTwoFactor = $totpEnabled || $passkeys->isNotEmpty())

<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-1">Sicherheit</h1>
        <p class="text-gray-500">Passkeys und Zwei-Faktor-Authentisierung für dein Konto.</p>
    </div>

    @if ($newRecoveryCodes)
        <x-card title="Wiederherstellungscodes" icon="key">
            <p class="text-sm text-gray-600 mb-3">
                Bewahre diese Codes an einem sicheren Ort auf. Jeder Code funktioniert einmal und
                ersetzt den zweiten Faktor, falls du keinen Zugriff mehr auf Passkey oder
                Authenticator-App hast. <strong>Sie werden nur jetzt angezeigt.</strong>
            </p>
            <div class="grid grid-cols-2 gap-2 rounded-md bg-gray-50 border border-gray-200 p-4 font-mono text-sm">
                @foreach ($newRecoveryCodes as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </x-card>
    @endif

    {{-- ===== Passkeys ===== --}}
    <x-card title="Passkeys" icon="key"
            description="Anmeldung mit Sicherheitsschlüssel, Fingerabdruck oder Geräte-PIN – als zweiter Faktor und passwortlos.">
        @if ($passkeys->isEmpty())
            <p class="text-sm text-gray-500 mb-4">Es ist noch kein Passkey hinterlegt.</p>
        @else
            <ul class="divide-y divide-gray-100 mb-4">
                @foreach ($passkeys as $passkey)
                    <li class="py-3 flex items-center justify-between gap-4" x-data="{ confirm: false }">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 truncate">{{ $passkey->name }}</div>
                            <div class="text-xs text-gray-500">
                                Hinzugefügt {{ $passkey->created_at->isoFormat('LL') }}
                                @if ($passkey->last_used_at)
                                    &middot; zuletzt verwendet {{ $passkey->last_used_at->diffForHumans() }}
                                @else
                                    &middot; noch nicht verwendet
                                @endif
                            </div>
                        </div>
                        <div class="shrink-0">
                            <x-button type="button" variant="secondary" size="sm" x-show="! confirm" @click="confirm = true">Entfernen</x-button>
                            <form method="POST" action="{{ route('profile.security.passkeys.destroy', $passkey) }}"
                                  x-show="confirm" x-cloak class="flex items-end gap-2">
                                @csrf
                                @method('DELETE')
                                @if ($requiresPassword)
                                    <div>
                                        <x-input-label value="Passwort" class="!text-xs" />
                                        <x-input type="password" name="current_password" required class="!py-1 text-xs" />
                                    </div>
                                @endif
                                <x-button type="submit" variant="danger" size="sm">Entfernen</x-button>
                                <x-button type="button" variant="secondary" size="sm" @click="confirm = false">Abbrechen</x-button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <form id="addPasskeyForm" class="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
            <div class="flex-1 min-w-[12rem]">
                <x-input-label value="Name für den neuen Passkey" />
                <x-input type="text" name="name" maxlength="50" required placeholder="z. B. YubiKey oder MacBook"
                         :error="$errors->first('name')" />
            </div>
            <x-button type="submit" id="addPasskeyButton">
                <x-icon name="plus" class="h-4 w-4" />
                <span>Passkey hinzufügen</span>
            </x-button>
        </form>
        <p id="addPasskeyError" class="hidden mt-2 text-sm text-red-600"></p>
        <p id="addPasskeyUnsupported" class="hidden mt-2 text-sm text-amber-600">
            Dieser Browser unterstützt keine Passkeys.
        </p>
    </x-card>

    {{-- ===== Authenticator-App ===== --}}
    <x-card title="Authenticator-App (TOTP)" icon="lock-closed"
            description="Zeitbasierte Einmalcodes aus einer App wie Google Authenticator, Aegis oder 1Password.">
        <div class="flex items-center justify-between gap-4">
            <div class="text-sm">
                @if ($totpEnabled)
                    <x-badge color="green">Aktiv</x-badge>
                @else
                    <x-badge color="gray">Nicht eingerichtet</x-badge>
                @endif
            </div>
            <div>
                @if ($totpEnabled)
                    <div x-data="{ confirm: false }">
                        <x-button type="button" variant="secondary" size="sm" x-show="! confirm" @click="confirm = true">Deaktivieren</x-button>
                        <form method="POST" action="{{ route('profile.security.totp.destroy') }}" x-show="confirm" x-cloak class="flex items-end gap-2">
                            @csrf
                            @method('DELETE')
                            @if ($requiresPassword)
                                <div>
                                    <x-input-label value="Passwort" class="!text-xs" />
                                    <x-input type="password" name="current_password" required class="!py-1 text-xs" />
                                </div>
                            @endif
                            <x-button type="submit" variant="danger" size="sm">Deaktivieren</x-button>
                            <x-button type="button" variant="secondary" size="sm" @click="confirm = false">Abbrechen</x-button>
                        </form>
                    </div>
                @else
                    <x-button tag="a" href="{{ route('profile.security.totp.setup') }}" variant="secondary" size="sm">Einrichten</x-button>
                @endif
            </div>
        </div>
        @error('code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-card>

    {{-- ===== Wiederherstellungscodes ===== --}}
    @if ($hasTwoFactor)
        <x-card title="Wiederherstellungscodes" icon="key">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm text-gray-600">
                    Noch <strong>{{ $recoveryCount }}</strong> von 8 Codes übrig.
                </p>
                <div x-data="{ confirm: false }">
                    <x-button type="button" variant="secondary" size="sm" x-show="! confirm" @click="confirm = true">Neue erzeugen</x-button>
                    <form method="POST" action="{{ route('profile.security.recovery-codes') }}" x-show="confirm" x-cloak class="flex items-end gap-2">
                        @csrf
                        @if ($requiresPassword)
                            <div>
                                <x-input-label value="Passwort" class="!text-xs" />
                                <x-input type="password" name="current_password" required class="!py-1 text-xs" />
                            </div>
                        @endif
                        <x-button type="submit" variant="danger" size="sm">Neu erzeugen</x-button>
                        <x-button type="button" variant="secondary" size="sm" @click="confirm = false">Abbrechen</x-button>
                    </form>
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500">Beim Erzeugen werden alle bisherigen Codes ungültig.</p>
        </x-card>
    @endif
</div>

@push('scripts')
<script src="{{ asset('vendor/webauthn/webauthn.js') }}"></script>
<script>
    (function () {
        var form = document.getElementById('addPasskeyForm');
        var button = document.getElementById('addPasskeyButton');
        var error = document.getElementById('addPasskeyError');
        if (!form) { return; }

        if (!window.Webauthn || !window.Webauthn.supported()) {
            document.getElementById('addPasskeyUnsupported').classList.remove('hidden');
            button.disabled = true;
            return;
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var name = form.querySelector('[name=name]').value.trim();
            if (!name) { return; }
            error.classList.add('hidden');
            button.disabled = true;

            window.Webauthn.register(
                '{{ route('profile.security.passkeys.options') }}',
                '{{ route('profile.security.passkeys.store') }}',
                '{{ csrf_token() }}',
                { name: name }
            ).then(function (res) {
                if (res.redirected) { window.location.href = res.url; return; }
                window.location.href = '{{ route('profile.security') }}';
            }).catch(function () {
                button.disabled = false;
                error.textContent = 'Der Passkey konnte nicht angelegt werden. Eventuell wurde der Vorgang abgebrochen.';
                error.classList.remove('hidden');
            });
        });
    })();
</script>
@endpush
@endsection
