@extends('layouts.admin')

@section('admin-content')
@php
    $methodCount = ($totpEnabled ? 1 : 0) + $passkeys->count();
    $hasTwoFactor = $methodCount > 0;

    if (! $hasTwoFactor) {
        $status = ['label' => 'Grundschutz', 'color' => 'amber',
            'text' => 'Dein Konto ist nur durch das Passwort geschützt. Richte einen zweiten Faktor ein.'];
    } elseif ($recoveryCount === 0) {
        $status = ['label' => 'Geschützt', 'color' => 'amber',
            'text' => 'Zweiter Faktor aktiv. Du hast keine Wiederherstellungscodes mehr, erzeuge neue.'];
    } elseif ($methodCount === 1) {
        $status = ['label' => 'Geschützt', 'color' => 'green',
            'text' => 'Zweiter Faktor aktiv. Eine zweite Methode als Reserve wird empfohlen.'];
    } else {
        $status = ['label' => 'Gut geschützt', 'color' => 'green',
            'text' => 'Zweiter Faktor mit mehreren Methoden aktiv.'];
    }

    $statusStyles = [
        'green' => ['wrap' => 'border-emerald-200 bg-emerald-50', 'icon' => 'text-emerald-600', 'title' => 'text-emerald-800'],
        'amber' => ['wrap' => 'border-amber-200 bg-amber-50', 'icon' => 'text-amber-600', 'title' => 'text-amber-800'],
    ][$status['color']];

    $eventLabels = [
        'login.success' => 'Anmeldung',
        'login.windows_sso' => 'Anmeldung über Windows',
        'webauthn.login' => 'Anmeldung mit Passkey',
        'two_factor.enabled' => 'Authenticator-App aktiviert',
        'two_factor.disabled' => 'Authenticator-App deaktiviert',
        'two_factor.recovery_used' => 'Wiederherstellungscode verwendet',
        'two_factor.recovery_regenerated' => 'Wiederherstellungscodes neu erzeugt',
        'webauthn.registered' => 'Passkey hinzugefügt',
        'webauthn.removed' => 'Passkey entfernt',
        'password.changed' => 'Passwort geändert',
    ];
@endphp

<div class="max-w-3xl space-y-6">
    <x-page-header title="Sicherheit" :back="route('profile.index')" back-label="Mein Account"
                   description="Passwort, zweiter Faktor und Passkeys für dein Konto." />

    @if ($newRecoveryCodes)
        <x-card title="Neue Wiederherstellungscodes" icon="key">
            <p class="mb-3 text-sm text-gray-600">
                Bewahre diese Codes an einem sicheren Ort auf. Jeder Code funktioniert einmal und
                ersetzt den zweiten Faktor, falls du keinen Zugriff mehr auf Passkey oder
                Authenticator-App hast. <strong>Sie werden nur jetzt angezeigt.</strong>
            </p>
            <div class="grid grid-cols-2 gap-2 rounded-md border border-gray-200 bg-gray-50 p-4 font-mono text-sm">
                @foreach ($newRecoveryCodes as $code)
                    <div>{{ $code }}</div>
                @endforeach
            </div>
        </x-card>
    @endif

    {{-- Status --}}
    <div class="flex items-start gap-3 rounded-lg border p-4 {{ $statusStyles['wrap'] }}">
        <x-icon name="shield-check" class="mt-0.5 h-5 w-5 shrink-0 {{ $statusStyles['icon'] }}" />
        <div>
            <div class="text-sm font-semibold {{ $statusStyles['title'] }}">{{ $status['label'] }}</div>
            <p class="mt-0.5 text-sm text-gray-600">{{ $status['text'] }}</p>
        </div>
    </div>

    {{-- Passwort --}}
    @if ($requiresPassword)
        <x-card title="Passwort" icon="lock-closed"
                description="Ändere das Passwort deines Kontos. Deine anderen Sitzungen bleiben danach aktiv.">
            <form method="POST" action="{{ route('profile.security.password') }}" class="max-w-md space-y-3">
                @csrf
                <div>
                    <x-input-label value="Aktuelles Passwort" />
                    <x-input type="password" name="current_password" autocomplete="current-password" required
                             :error="$errors->first('current_password')" />
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
                <x-button type="submit" size="sm">Passwort ändern</x-button>
            </form>
        </x-card>
    @endif

    {{-- Passkeys --}}
    <x-card title="Passkeys" icon="key"
            description="Anmeldung mit Sicherheitsschlüssel, Fingerabdruck oder Geräte-PIN.">
        <x-slot:actions>
            <x-badge :color="$passkeys->isNotEmpty() ? 'green' : 'gray'">
                {{ $passkeys->count() }} {{ $passkeys->count() === 1 ? 'Passkey' : 'Passkeys' }}
            </x-badge>
        </x-slot:actions>

        @if ($passkeys->isNotEmpty())
            <ul class="mb-4 divide-y divide-gray-100">
                @foreach ($passkeys as $passkey)
                    <li class="flex items-center justify-between gap-4 py-3" x-data="{ confirm: false }">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-laravel-50 text-laravel-600">
                                <x-icon name="key" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium text-gray-900">{{ $passkey->name }}</div>
                                <div class="text-xs text-gray-500">
                                    Hinzugefügt {{ $passkey->created_at->isoFormat('LL') }}
                                    @if ($passkey->last_used_at)
                                        &middot; zuletzt verwendet {{ $passkey->last_used_at->diffForHumans() }}
                                    @else
                                        &middot; noch nicht verwendet
                                    @endif
                                </div>
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
        @else
            <p class="mb-4 text-sm text-gray-500">Es ist noch kein Passkey hinterlegt.</p>
        @endif

        <form id="addPasskeyForm" class="flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
            <div class="min-w-[12rem] flex-1">
                <x-input-label value="Name für den neuen Passkey" />
                <x-input type="text" name="name" maxlength="50" required placeholder="z. B. YubiKey oder MacBook"
                         :error="$errors->first('name')" />
            </div>
            <x-button type="submit" id="addPasskeyButton">
                <x-icon name="plus" class="h-4 w-4" />
                <span>Passkey hinzufügen</span>
            </x-button>
        </form>
        <p id="addPasskeyError" class="mt-2 hidden text-sm text-red-600"></p>
        <p id="addPasskeyUnsupported" class="mt-2 hidden text-sm text-amber-600">
            Dieser Browser unterstützt keine Passkeys.
        </p>
    </x-card>

    {{-- Authenticator-App --}}
    <x-card title="Authenticator-App" icon="lock-closed"
            description="Zeitbasierte Einmalcodes aus einer App wie Google Authenticator, Aegis oder 1Password.">
        <x-slot:actions>
            <x-badge :color="$totpEnabled ? 'green' : 'gray'">{{ $totpEnabled ? 'Aktiv' : 'Nicht eingerichtet' }}</x-badge>
        </x-slot:actions>

        @if ($totpEnabled)
            <div x-data="{ confirm: false }">
                <p class="mb-3 text-sm text-gray-600">Beim Anmelden wirst du nach einem Code aus deiner App gefragt.</p>
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
            <p class="mb-3 text-sm text-gray-500">Noch nicht eingerichtet.</p>
            <x-button tag="a" href="{{ route('profile.security.totp.setup') }}" variant="secondary" size="sm">Einrichten</x-button>
        @endif
        @error('code')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-card>

    {{-- Wiederherstellungscodes --}}
    @if ($hasTwoFactor)
        <x-card title="Wiederherstellungscodes" icon="key"
                description="Einmal-Codes für den Fall, dass du keinen Zugriff mehr auf deinen zweiten Faktor hast.">
            <x-slot:actions>
                <x-badge :color="$recoveryCount === 0 ? 'amber' : 'gray'">{{ $recoveryCount }} übrig</x-badge>
            </x-slot:actions>

            <div x-data="{ confirm: false }">
                <p class="mb-3 text-sm text-gray-600">Noch <strong>{{ $recoveryCount }}</strong> von 8 Codes verfügbar.</p>
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
                <p class="mt-2 text-xs text-gray-500">Beim Erzeugen werden alle bisherigen Codes ungültig.</p>
            </div>
        </x-card>
    @endif

    {{-- Vertraute Geräte --}}
    @if ($trustedDevices->isNotEmpty())
        <x-card title="Vertraute Geräte" icon="shield-check"
                description="Auf diesen Geräten wird der zweite Faktor eine Weile nicht abgefragt. Entferne Geräte, die du nicht mehr nutzt oder nicht wiedererkennst.">
            <x-slot:actions>
                <form method="POST" action="{{ route('profile.security.trusted-devices.destroy-all') }}"
                      onsubmit="return confirm('Alle vertrauten Geräte entfernen?')">
                    @csrf
                    @method('DELETE')
                    <x-button type="submit" variant="secondary" size="sm">Alle entfernen</x-button>
                </form>
            </x-slot:actions>

            <ul class="divide-y divide-gray-100">
                @foreach ($trustedDevices as $device)
                    <li class="flex items-center justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-900">{{ $device->label ?? 'Unbekanntes Gerät' }}</p>
                            <p class="text-xs text-gray-500">
                                zuletzt {{ optional($device->last_used_at)->diffForHumans() ?? 'nie' }}
                                @if ($device->ip_address) · {{ $device->ip_address }} @endif
                                · gültig bis {{ $device->expires_at->format('d.m.Y') }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('profile.security.trusted-devices.destroy', $device) }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" variant="secondary" size="sm">Entfernen</x-button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    {{-- Letzte Aktivität --}}
    @if ($recentEvents->isNotEmpty())
        <x-card title="Letzte Aktivität" icon="journal" :padding="false">
            <ul class="divide-y divide-gray-100">
                @foreach ($recentEvents as $event)
                    <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm sm:px-6">
                        <div class="min-w-0">
                            <div class="text-gray-800">{{ $eventLabels[$event->event] ?? $event->event }}</div>
                            @if ($event->ip_address)
                                <div class="text-xs text-gray-400">{{ $event->ip_address }}</div>
                            @endif
                        </div>
                        <span class="shrink-0 text-xs text-gray-400" title="{{ $event->created_at->format('d.m.Y H:i') }}">
                            {{ $event->created_at->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
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
