@extends('layouts.admin')

@php
    $sections = [
        ['id' => 'general', 'label' => 'Allgemein', 'icon' => 'cog'],
        ['id' => 'appearance', 'label' => 'Erscheinungsbild', 'icon' => 'paint'],
        ['id' => 'images', 'label' => 'Bilder', 'icon' => 'image'],
        ['id' => 'login', 'label' => 'Anmeldung', 'icon' => 'login'],
        ['id' => 'security', 'label' => 'Sicherheit', 'icon' => 'shield-check'],
        ['id' => 'audit', 'label' => 'Protokoll', 'icon' => 'journal'],
        ['id' => 'maintenance', 'label' => 'Wartung', 'icon' => 'warning'],
    ];

    $accent = old('accent_color', $settings['accent_color'] ?: \App\Support\AccentPalette::DEFAULT);
    $storage = \Illuminate\Support\Facades\Storage::disk('public');
@endphp

@section('admin-content')
<x-page-header
    title="Systemeinstellungen"
    description="Grunddaten, Erscheinungsbild und Anmeldung des Systems. Änderungen wirken sofort für alle Benutzer." />

<div
    x-data="{
        tab: (() => {
            @if ($errors->any()) return 'general'; @endif
            try { return localStorage.getItem('idp_settings_tab') || 'general'; } catch (e) { return 'general'; }
        })(),
        accent: @js($accent),
        iconMode: @js(old('brand_icon_mode', $settings['brand_icon_mode'] ?: 'default')),
        iconShape: @js(old('brand_icon_shape', $settings['brand_icon_shape'] ?: 'rounded')),
        loginMode: @js(old('login_title_mode', $settings['login_title_mode'] ?: 'default')),
        loginText: @js(old('login_title_text', $settings['login_title_text'] ?? '')),
        systemName: @js(old('system_name', $settings['system_name'] ?? '')),
        hasLogo: @js((bool) $logoPath),
        logoUrl: @js($logoPath ? $storage->url($logoPath) : ''),
        get iconInitial() { return (this.systemName.trim()[0] || 'A').toUpperCase(); },
        get shapeClass() { return { rounded: 'rounded-md', circle: 'rounded-full', square: 'rounded-none' }[this.iconShape] || 'rounded-md'; },
        get loginPreview() {
            if (this.loginMode === 'hidden') return '';
            if (this.loginMode === 'custom') return this.loginText.trim();
            return this.systemName.trim() || 'System';
        },
    }"
    x-init="$watch('tab', v => { try { localStorage.setItem('idp_settings_tab', v); } catch (e) {} })"
    x-cloak
>
    {{-- Mobile: Abschnittswahl --}}
    <div class="mb-5 flex gap-2 overflow-x-auto pb-1 lg:hidden">
        @foreach ($sections as $s)
            <button type="button" @click="tab = '{{ $s['id'] }}'"
                    :class="tab === '{{ $s['id'] }}' ? 'bg-laravel-600 text-white' : 'border border-gray-200 bg-white text-gray-600'"
                    class="shrink-0 rounded-full px-3 py-1.5 text-sm font-medium transition">{{ $s['label'] }}</button>
        @endforeach
    </div>

    <div class="lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8">
        {{-- Desktop: Abschnittsnavigation --}}
        <nav class="hidden lg:block">
            <div class="space-y-1">
                @foreach ($sections as $s)
                    <button type="button" @click="tab = '{{ $s['id'] }}'"
                            :class="tab === '{{ $s['id'] }}' ? 'bg-laravel-50 text-laravel-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
                            class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm font-medium transition">
                        <x-icon name="{{ $s['icon'] }}" class="h-4 w-4 shrink-0" />
                        {{ $s['label'] }}
                    </button>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 space-y-6">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                {{-- ===== Allgemein ===== --}}
                <div x-show="tab === 'general'">
                    <x-card title="Allgemein" description="Name, Adresse und grundlegendes Verhalten.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Systemname" hint="Erscheint im Kopfbereich, im Browser-Tab und auf der Anmeldeseite.">
                                <x-input type="text" name="system_name" x-model="systemName"
                                         value="{{ old('system_name', $settings['system_name']) }}" required />
                            </x-setting-row>

                            <x-setting-row label="Basis-URL" hint="Die Web-Adresse, unter der das System erreichbar ist, z. B. <code>https://login.firma.de</code>.">
                                <x-input type="url" name="base_url" value="{{ old('base_url', $settings['base_url']) }}" required />
                            </x-setting-row>

                            <x-setting-row label="Zeitzone" hint="Zeitzone für Zeitstempel im Audit-Log und in der Verwaltung, z. B. <code>Europe/Berlin</code>.">
                                <x-input type="text" name="timezone" value="{{ old('timezone', $settings['timezone']) }}" required />
                            </x-setting-row>

                            <x-setting-row label="Sprache" hint="Sprache der Oberfläche. Es stehen nur Sprachen zur Auswahl, für die Übersetzungen vorliegen.">
                                @php($locales = \App\Support\Locales::available())
                                @php($currentLocale = old('locale', $settings['locale'] ?: 'de'))
                                <x-select name="locale" class="!max-w-xs" required>
                                    @foreach ($locales as $code => $name)
                                        <option value="{{ $code }}" @selected($currentLocale === $code)>{{ $name }}</option>
                                    @endforeach
                                </x-select>
                            </x-setting-row>

                            <x-setting-row label="Automatische Abmeldung" hint="Nach so vielen Minuten ohne Aktivität muss man sich neu anmelden.">
                                <div class="flex items-center gap-2">
                                    <x-input type="number" name="session_lifetime" min="5"
                                             value="{{ old('session_lifetime', $settings['session_lifetime']) }}" required class="!w-28" />
                                    <span class="text-sm text-gray-500">Minuten</span>
                                </div>
                            </x-setting-row>
                        </div>
                    </x-card>
                </div>

                {{-- ===== Erscheinungsbild ===== --}}
                <div x-show="tab === 'appearance'" class="space-y-6">
                    <x-card title="Vorschau" description="So wirken die Einstellungen auf Kopfbereich und Anmeldeseite. Ein hochgeladenes Banner ersetzt das Symbol.">
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            {{-- Kopfbereich --}}
                            <div class="flex items-center gap-2 border-b border-gray-200 bg-white px-3 py-2.5">
                                <template x-if="hasLogo">
                                    <img :src="logoUrl" alt="" class="h-7 max-w-[8rem] object-contain">
                                </template>
                                <template x-if="! hasLogo && iconMode !== 'hidden'">
                                    <span class="flex h-7 w-7 items-center justify-center text-white" :class="shapeClass" :style="`background:${accent}`">
                                        <span x-show="iconMode === 'initial'" class="text-xs font-semibold" x-text="iconInitial"></span>
                                        <svg x-show="iconMode !== 'initial'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                                    </span>
                                </template>
                                <span class="truncate text-sm font-semibold text-gray-800" x-text="systemName.trim() || 'System'"></span>
                                <span class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium text-white" :style="`background:${accent}`">Administration</span>
                            </div>
                            {{-- Anmeldeseite --}}
                            <div class="flex flex-col items-center gap-2 bg-gray-50 px-3 py-6">
                                <template x-if="hasLogo">
                                    <img :src="logoUrl" alt="" class="h-11 max-w-[10rem] object-contain">
                                </template>
                                <template x-if="! hasLogo && iconMode !== 'hidden'">
                                    <span class="flex h-11 w-11 items-center justify-center text-white" :class="shapeClass" :style="`background:${accent}`">
                                        <span x-show="iconMode === 'initial'" class="text-base font-semibold" x-text="iconInitial"></span>
                                        <svg x-show="iconMode !== 'initial'" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                                    </span>
                                </template>
                                <span class="text-sm font-semibold text-gray-800" x-show="loginPreview" x-text="loginPreview"></span>
                                <div class="mt-1 w-full max-w-[13rem] space-y-1.5">
                                    <div class="h-6 rounded border border-gray-200 bg-white"></div>
                                    <div class="h-6 rounded border border-gray-200 bg-white"></div>
                                    <div class="h-6 rounded text-center text-[11px] font-medium leading-6 text-white" :style="`background:${accent}`">Anmelden</div>
                                </div>
                            </div>
                        </div>
                    </x-card>

                    <x-card title="Farbe & Symbol">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Akzentfarbe" hint="Wird systemweit für Schaltflächen, Links und Hervorhebungen verwendet.">
                                <div class="flex flex-wrap items-center gap-3">
                                    <input type="color" x-model="accent" aria-label="Farbe wählen"
                                           class="h-9 w-12 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-1">
                                    <x-input type="text" name="accent_color" x-model="accent" maxlength="7"
                                             value="{{ $accent }}" class="!w-32 font-mono uppercase" />
                                    <div class="flex gap-1.5">
                                        <template x-for="p in ['#FF2D20','#2563EB','#059669','#7C3AED','#DB2777','#EA580C','#0891B2','#475569']" :key="p">
                                            <button type="button" @click="accent = p" :style="`background:${p}`" :title="p"
                                                    class="h-6 w-6 rounded-full border border-black/10"
                                                    :class="accent.toUpperCase() === p ? 'ring-2 ring-gray-400 ring-offset-1' : ''"></button>
                                        </template>
                                    </div>
                                </div>
                            </x-setting-row>

                            <x-setting-row label="Symbol" hint="Das kleine Zeichen im Kopfbereich und über dem Anmeldeformular.">
                                <div class="flex flex-wrap gap-3">
                                    <x-select name="brand_icon_mode" x-model="iconMode" class="!w-64">
                                        <option value="default">Standard-Zeichen (Schild)</option>
                                        <option value="initial">Anfangsbuchstabe des Systemnamens</option>
                                        <option value="hidden">Ausblenden</option>
                                    </x-select>
                                    <x-select name="brand_icon_shape" x-model="iconShape" x-show="iconMode !== 'hidden'" class="!w-40">
                                        <option value="rounded">Abgerundet</option>
                                        <option value="circle">Rund</option>
                                        <option value="square">Eckig</option>
                                    </x-select>
                                </div>
                            </x-setting-row>

                            <x-setting-row label="Titel auf der Anmeldeseite" hint="Der Text unter dem Symbol über dem Anmeldeformular.">
                                <div class="space-y-2">
                                    <x-select name="login_title_mode" x-model="loginMode" class="!w-64">
                                        <option value="default">Systemnamen anzeigen</option>
                                        <option value="hidden">Ausblenden</option>
                                        <option value="custom">Eigener Text</option>
                                    </x-select>
                                    <x-input type="text" name="login_title_text" maxlength="255" x-model="loginText"
                                             x-show="loginMode === 'custom'"
                                             value="{{ old('login_title_text', $settings['login_title_text']) }}"
                                             placeholder="z. B. Willkommen" class="!w-64" />
                                </div>
                            </x-setting-row>
                        </div>
                    </x-card>
                </div>

                {{-- ===== Anmeldung ===== --}}
                <div x-show="tab === 'login'">
                    <x-card title="Windows-Anmeldung (Single Sign-On)"
                            description="Betrifft nur Verzeichnis-Konten. Lokale Konten melden sich immer über die Anmeldeseite an.">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="hidden" name="windows_sso_enabled" value="0">
                            <x-checkbox name="windows_sso_enabled" value="1" class="mt-0.5"
                                        :checked="old('windows_sso_enabled', $settings['windows_sso_enabled'] ?? '1') !== '0'" />
                            <span>
                                <span class="text-sm font-medium text-gray-900">Automatische Windows-Anmeldung aktiv</span>
                                <span class="mt-1 block text-xs leading-relaxed text-gray-500">
                                    Ist sie aktiv, werden Benutzer automatisch über ihr Windows-Konto angemeldet, sobald der
                                    Webserver die Identität liefert. Ist sie aus, erscheint für alle die normale Anmeldeseite,
                                    auch wenn der Webserver Windows-Authentifizierung macht.
                                </span>
                            </span>
                        </label>
                    </x-card>
                </div>

                {{-- ===== Sicherheit ===== --}}
                <div x-show="tab === 'security'" class="space-y-6">
                    <x-card title="Passwort-Richtlinie"
                            description="Gilt für lokale Konten: beim Anlegen, beim Zurücksetzen durch einen Administrator und bei der Einrichtung. Verzeichnis-Konten sind nicht betroffen.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Mindestlänge">
                                <div class="flex items-center gap-2">
                                    <x-input type="number" name="password_min_length" min="6" max="128" class="!w-24"
                                             value="{{ old('password_min_length', $settings['password_min_length'] ?: '10') }}" />
                                    <span class="text-sm text-gray-500">Zeichen</span>
                                </div>
                            </x-setting-row>
                            <x-setting-row label="Zusammensetzung">
                                <div class="space-y-2">
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                        <input type="hidden" name="password_require_mixed_case" value="0">
                                        <x-checkbox name="password_require_mixed_case" value="1" :checked="old('password_require_mixed_case', $settings['password_require_mixed_case']) === '1'" />
                                        Groß- und Kleinbuchstaben verlangen
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                        <input type="hidden" name="password_require_number" value="0">
                                        <x-checkbox name="password_require_number" value="1" :checked="old('password_require_number', $settings['password_require_number']) === '1'" />
                                        Mindestens eine Ziffer verlangen
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                        <input type="hidden" name="password_require_symbol" value="0">
                                        <x-checkbox name="password_require_symbol" value="1" :checked="old('password_require_symbol', $settings['password_require_symbol']) === '1'" />
                                        Mindestens ein Sonderzeichen verlangen
                                    </label>
                                </div>
                            </x-setting-row>
                            <x-setting-row label="Bekannte Datenlecks"
                                           hint="Prüft neue Passwörter gegen die freie Pwned-Passwords-Datenbank.">
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                    <input type="hidden" name="password_check_pwned" value="0">
                                    <x-checkbox name="password_check_pwned" value="1" :checked="old('password_check_pwned', $settings['password_check_pwned']) === '1'" />
                                    Passwörter aus bekannten Datenlecks ablehnen
                                </label>
                            </x-setting-row>
                        </div>
                    </x-card>

                    <x-card title="Anmelde-Sperre"
                            description="Schützt die Anmeldeseite gegen automatisiertes Durchprobieren. Zählt pro Benutzername und IP-Adresse.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Fehlversuche bis zur Sperre">
                                <x-input type="number" name="login_max_attempts" min="3" max="100" class="!w-24"
                                         value="{{ old('login_max_attempts', $settings['login_max_attempts'] ?: '5') }}" />
                            </x-setting-row>
                            <x-setting-row label="Sperrdauer">
                                <div class="flex items-center gap-2">
                                    <x-input type="number" name="login_lockout_minutes" min="1" max="1440" class="!w-24"
                                             value="{{ old('login_lockout_minutes', $settings['login_lockout_minutes'] ?: '1') }}" />
                                    <span class="text-sm text-gray-500">Minuten</span>
                                </div>
                            </x-setting-row>
                        </div>
                    </x-card>
                </div>

                {{-- ===== Protokoll ===== --}}
                <div x-show="tab === 'audit'" class="space-y-6">
                    <x-card title="Aufbewahrung"
                            description="Ältere Audit-Log-Einträge werden nachts automatisch gelöscht. Die vollständige Historie lässt sich vorher über den Export auf der Audit-Log-Seite sichern.">
                        <x-setting-row label="Aufbewahrungsfrist" hint="Anzahl Tage, die ein Eintrag behalten wird. 0 = unbegrenzt aufbewahren.">
                            <div class="flex items-center gap-2">
                                <x-input type="number" name="audit_log_retention_days" min="0" max="36500"
                                         value="{{ old('audit_log_retention_days', $settings['audit_log_retention_days'] ?: '0') }}" class="!w-28" />
                                <span class="text-sm text-gray-500">Tage</span>
                            </div>
                        </x-setting-row>
                    </x-card>

                    <x-card title="Weiterleitung an Syslog / SIEM"
                            description="Sendet jedes Audit-Ereignis zusätzlich als JSON-Zeile per Syslog an einen externen Empfänger (z. B. Graylog, Splunk, Wazuh, rsyslog). Die lokale Speicherung bleibt unverändert.">
                        <div class="space-y-4"
                             x-data="{ on: {{ old('audit_forward_enabled', $settings['audit_forward_enabled'] ?? '0') === '1' ? 'true' : 'false' }} }">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="hidden" name="audit_forward_enabled" value="0">
                                <x-checkbox name="audit_forward_enabled" value="1" class="mt-0.5" x-model="on" />
                                <span class="text-sm font-medium text-gray-900">Weiterleitung aktiv</span>
                            </label>

                            <div class="divide-y divide-gray-100" x-show="on" x-cloak>
                                <x-setting-row label="Empfänger (Host)" hint="Hostname oder IP des Syslog-Empfängers.">
                                    <x-input type="text" name="audit_forward_host"
                                             value="{{ old('audit_forward_host', $settings['audit_forward_host']) }}"
                                             placeholder="siem.firma.local" />
                                </x-setting-row>
                                <x-setting-row label="Port">
                                    <x-input type="number" name="audit_forward_port" min="1" max="65535"
                                             value="{{ old('audit_forward_port', $settings['audit_forward_port'] ?: '514') }}" class="!w-28" />
                                </x-setting-row>
                                <x-setting-row label="Protokoll">
                                    @php($proto = old('audit_forward_protocol', $settings['audit_forward_protocol'] ?: 'udp'))
                                    <x-select name="audit_forward_protocol" class="!w-40">
                                        <option value="udp" @selected($proto === 'udp')>UDP</option>
                                        <option value="tcp" @selected($proto === 'tcp')>TCP</option>
                                    </x-select>
                                </x-setting-row>
                            </div>
                        </div>
                    </x-card>
                </div>

                {{-- ===== Wartung ===== --}}
                <div x-show="tab === 'maintenance'">
                    <x-card title="Wartungsmodus (gesamtes System)"
                            description="Ist er aktiv, sieht jeder eine Wartungsseite. Lokale Administratoren und die unten freigegebenen Benutzer kommen weiterhin rein, die Anmeldeseite bleibt für alle erreichbar.">
                        <div class="space-y-4">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="hidden" name="maintenance_mode" value="0">
                                <x-checkbox name="maintenance_mode" value="1" class="mt-0.5"
                                            :checked="old('maintenance_mode', $settings['maintenance_mode']) === '1'" />
                                <span class="text-sm font-medium text-gray-900">Wartungsmodus jetzt aktivieren</span>
                            </label>

                            <div>
                                <x-input-label value="Wartungsmeldung" />
                                <x-textarea name="maintenance_message" rows="2"
                                            placeholder="Das System wird zurzeit gewartet. Bitte später erneut versuchen.">{{ old('maintenance_message', $settings['maintenance_message']) }}</x-textarea>
                            </div>

                            <div>
                                <x-input-label value="Wer trotzdem rein darf" />
                                <p class="mb-1 mt-1 text-xs text-gray-500">Ein Eintrag pro Zeile: Benutzername oder <code>@Gruppenname</code>. Lokale Administratoren haben immer Zugriff.</p>
                                <x-textarea name="maintenance_allow" rows="3" placeholder="mmustermann&#10;@IT-Abteilung">{{ old('maintenance_allow', $settings['maintenance_allow']) }}</x-textarea>
                            </div>
                        </div>
                    </x-card>
                </div>

                {{-- Speicherleiste (nicht bei "Bilder") --}}
                <div x-show="tab !== 'images'"
                     class="sticky bottom-0 -mx-4 flex items-center gap-3 border-t border-gray-200 bg-gray-100 px-4 py-3 sm:mx-0 sm:rounded-lg sm:border sm:bg-white">
                    <x-button type="submit">Speichern</x-button>
                    <span class="text-xs text-gray-500">Gilt für alle Abschnitte außer „Bilder".</span>
                </div>
            </form>

            {{-- ===== Bilder (eigene Formulare, außerhalb des Einstellungsformulars) ===== --}}
            <div x-show="tab === 'images'" class="space-y-6">
                <x-card title="Banner (Logo)"
                        description="Ersetzt das Symbol im Kopfbereich und auf der Anmeldeseite. Am besten ein PNG mit durchsichtigem Hintergrund.">
                    @if ($logoPath)
                        <div class="mb-4 flex items-center gap-4">
                            <img src="{{ $storage->url($logoPath) }}" alt="Aktuelles Banner" class="h-12 max-w-xs rounded-md border border-gray-200 object-contain p-2">
                            <x-confirm-form :action="route('admin.settings.logo.delete')" message="Banner wirklich entfernen?" label="Entfernen" size="sm" />
                        </div>
                    @else
                        <p class="mb-4 text-sm text-gray-400">Kein Banner hochgeladen. Es wird das eingestellte Symbol angezeigt.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.settings.logo.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                        @csrf
                        <div class="flex-1">
                            <x-input-label value="Neues Banner hochladen" />
                            <input type="file" name="logo" accept="image/*" required class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
                        </div>
                        <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                    </form>
                </x-card>

                <x-card title="Favicon" description="Das kleine Symbol im Browser-Tab.">
                    @if ($faviconPath)
                        <div class="mb-4 flex items-center gap-4">
                            <img src="{{ $storage->url($faviconPath) }}" alt="Aktuelles Favicon" class="h-8 w-8 rounded-md border border-gray-200 object-contain p-1">
                            <x-confirm-form :action="route('admin.settings.favicon.delete')" message="Favicon wirklich entfernen?" label="Entfernen" size="sm" />
                        </div>
                    @else
                        <p class="mb-4 text-sm text-gray-400">Kein Favicon hochgeladen.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.settings.favicon.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                        @csrf
                        <div class="flex-1">
                            <x-input-label value="Neues Favicon hochladen" />
                            <input type="file" name="favicon" accept="image/*,.ico,image/x-icon,image/vnd.microsoft.icon" required class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
                            <p class="mt-1 text-xs text-gray-400">PNG, WebP oder ICO.</p>
                        </div>
                        <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                    </form>
                </x-card>

                <x-card title="Login-Hintergrund" description="Vollflächiges Hintergrundbild der Anmeldeseite. Ohne Bild bleibt der Hintergrund schlicht.">
                    @if ($loginBackgroundPath)
                        <div class="mb-4">
                            <img src="{{ $storage->url($loginBackgroundPath) }}" alt="Aktueller Login-Hintergrund" class="max-h-48 w-full rounded-md border border-gray-200 object-cover">
                            <div class="mt-3">
                                <x-confirm-form :action="route('admin.settings.login-background.delete')" message="Login-Hintergrund wirklich entfernen?" label="Entfernen" size="sm" />
                            </div>
                        </div>
                    @else
                        <p class="mb-4 text-sm text-gray-400">Kein Hintergrundbild. Die Anmeldeseite zeigt einen neutralen Hintergrund.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.settings.login-background.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                        @csrf
                        <div class="flex-1">
                            <x-input-label value="Neues Hintergrundbild hochladen" />
                            <input type="file" name="login_background" accept="image/*" required class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-md file:border-0 file:bg-laravel-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-laravel-700 hover:file:bg-laravel-100">
                            <p class="mt-1 text-xs text-gray-400">Empfohlen: breites Bild (z. B. 1920 x 1080), höchstens 8 MB.</p>
                        </div>
                        <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </div>
</div>
@endsection
