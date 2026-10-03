@extends('layouts.admin')

@php
    $sections = [
        ['id' => 'overview', 'label' => 'Übersicht', 'icon' => 'grid', 'desc' => 'Was ist eingerichtet, was fehlt noch?'],
        ['id' => 'general', 'label' => 'Allgemein', 'icon' => 'cog', 'desc' => 'Name, Adresse, Zeitzone, Sprache'],
        ['id' => 'appearance', 'label' => 'Erscheinungsbild', 'icon' => 'paint', 'desc' => 'Farbe, Symbol, Titel, Banner-Größe'],
        ['id' => 'images', 'label' => 'Bilder', 'icon' => 'image', 'desc' => 'Banner, Favicon, Login-Hintergrund'],
        ['id' => 'login', 'label' => 'Anmeldung', 'icon' => 'login', 'desc' => 'Windows-Anmeldung'],
        ['id' => 'security', 'label' => 'Sicherheit', 'icon' => 'shield-check', 'desc' => 'Passwörter, Sperre, Zwei-Faktor, Passkeys'],
        ['id' => 'email', 'label' => 'E-Mail', 'icon' => 'mail', 'desc' => 'Versand und Testnachricht'],
        ['id' => 'audit', 'label' => 'Protokoll', 'icon' => 'journal', 'desc' => 'Aufbewahrung und Syslog'],
        ['id' => 'maintenance', 'label' => 'Wartung', 'icon' => 'warning', 'desc' => 'System für Benutzer sperren'],
    ];

    $mailEnabled = ($settings['mail_enabled'] ?? '0') === '1';
    $maintenanceOn = ($settings['maintenance_mode'] ?? '0') === '1';
    $syslogOn = ($settings['audit_forward_enabled'] ?? '0') === '1';
    $retentionDays = (int) ($settings['audit_log_retention_days'] ?? 0);
    $usesHttps = str_starts_with((string) ($settings['base_url'] ?? ''), 'https://');

    // Kurzstatus je Abschnitt, wird in der Navigation angezeigt.
    $sectionStatus = [
        'email' => $mailEnabled
            ? ($mailConfigured ? ['Aktiv', 'green'] : ['Unvollständig', 'amber'])
            : ['Aus', 'gray'],
        'maintenance' => $maintenanceOn ? ['Aktiv', 'amber'] : null,
        'audit' => $syslogOn ? ['Syslog', 'green'] : null,
        'images' => $logoPath ? null : ['Kein Banner', 'gray'],
    ];

    // In welchem Abschnitt liegt ein Feld? Damit Fehler den richtigen Abschnitt öffnen.
    $fieldSection = static function (string $field): string {
        return match (true) {
            in_array($field, ['logo', 'favicon', 'login_background'], true) => 'images',
            in_array($field, ['accent_color', 'login_layout', 'logo_height_header', 'logo_height_login'], true),
            str_starts_with($field, 'brand_icon_'), str_starts_with($field, 'login_title_'), str_starts_with($field, 'header_title_') => 'appearance',
            $field === 'windows_sso_enabled' => 'login',
            str_starts_with($field, 'password_'), str_starts_with($field, 'login_'), str_starts_with($field, 'trusted_device_'),
            str_starts_with($field, 'passkey_'), str_starts_with($field, 'new_device_') => 'security',
            str_starts_with($field, 'mail_'), $field === 'test_email' => 'email',
            str_starts_with($field, 'audit_') => 'audit',
            str_starts_with($field, 'maintenance_') => 'maintenance',
            default => 'general',
        };
    };
    $errorSections = collect($errors->keys())->map($fieldSection)->unique()->values();

    // "Übersicht": Prüfpunkte mit Zustand und Sprung in den passenden Abschnitt.
    $checks = [
        [
            'ok' => $usesHttps, 'section' => 'general', 'action' => 'Prüfen',
            'title' => 'Basis-URL mit HTTPS',
            'text' => $usesHttps
                ? 'Das System ist über HTTPS erreichbar.'
                : 'Die Basis-URL beginnt nicht mit https://. Anmeldungen sollten verschlüsselt laufen.',
        ],
        [
            'ok' => $mailEnabled && $mailConfigured, 'section' => 'email', 'action' => 'Einrichten',
            'title' => 'E-Mail-Versand',
            'text' => $mailEnabled && $mailConfigured
                ? 'Aktiv. Passwort-Zurücksetzen und Benachrichtigungen per E-Mail funktionieren.'
                : 'Nicht eingerichtet. Ohne E-Mail-Versand gibt es kein „Passwort vergessen“ und keine Benachrichtigungen.',
        ],
        [
            'ok' => (bool) $logoPath, 'optional' => true, 'section' => 'images', 'action' => 'Hochladen',
            'title' => 'Banner (Logo)',
            'text' => $logoPath ? 'Ein Banner ist hochgeladen.' : 'Optional. Ohne Banner wird das Symbol angezeigt.',
        ],
        [
            'ok' => ! $maintenanceOn, 'section' => 'maintenance', 'action' => 'Öffnen',
            'title' => 'Wartungsmodus',
            'text' => $maintenanceOn
                ? 'Aktiv. Normale Benutzer sehen nur die Wartungsseite.'
                : 'Aus. Das System ist normal erreichbar.',
        ],
        [
            'ok' => $retentionDays > 0, 'optional' => true, 'section' => 'audit', 'action' => 'Festlegen',
            'title' => 'Aufbewahrung des Audit-Logs',
            'text' => $retentionDays > 0
                ? "Einträge werden nach {$retentionDays} Tagen gelöscht."
                : 'Einträge werden unbegrenzt aufbewahrt. Eine Frist hält die Datenbank klein.',
        ],
    ];
    $openChecks = collect($checks)->filter(fn ($c) => ! $c['ok'] && empty($c['optional']))->count();
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
            @if ($errorSections->isNotEmpty()) return @js($errorSections->first()); @endif
            try { return localStorage.getItem('idp_settings_tab') || 'overview'; } catch (e) { return 'overview'; }
        })(),
        dirty: false,
        saving: false,
        showChanges: false,
        initial: {},
        changes: [],
        fields() {
            return [...this.$refs.form.querySelectorAll('input[name], select[name], textarea[name]')]
                .filter(el => el.type !== 'hidden' && el.type !== 'file' && el.type !== 'submit' && ! el.name.startsWith('_'));
        },
        fieldValue(el) {
            if (el.type === 'checkbox') return el.checked ? 'an' : 'aus';
            if (el.tagName === 'SELECT') return el.options[el.selectedIndex]?.text.trim() ?? '';
            return el.value;
        },
        fieldLabel(el) {
            const label = el.closest('label');
            const own = label ? label.textContent.replace(/\s+/g, ' ').trim() : '';
            const row = el.closest('[data-label]');
            if (el.type === 'checkbox' && own) return row ? row.dataset.label + ': ' + own : own;
            if (row) return row.dataset.label;
            if (own) return own;
            const prev = el.parentElement?.querySelector('label');
            return (prev ? prev.textContent.replace(/\s+/g, ' ').trim() : '') || el.name;
        },
        snapshot() {
            this.initial = Object.fromEntries(this.fields().map((el, i) => [i, this.fieldValue(el)]));
            this.changes = [];
            this.dirty = false;
        },
        updateChanges() {
            const secret = el => el.type === 'password';
            const shorten = v => v === '' ? 'leer' : (v.length > 40 ? v.slice(0, 40) + '…' : v);
            this.changes = this.fields().map((el, i) => ({ el, i, now: this.fieldValue(el) }))
                .filter(c => c.now !== this.initial[c.i])
                .map(c => ({
                    label: this.fieldLabel(c.el),
                    from: secret(c.el) ? '' : shorten(this.initial[c.i] ?? ''),
                    to: secret(c.el) ? 'neu gesetzt' : shorten(c.now),
                    secret: secret(c.el),
                    i: c.i,
                }));
            this.dirty = this.changes.length > 0;
            if (! this.dirty) this.showChanges = false;
        },
        accent: @js($accent),
        iconMode: @js(old('brand_icon_mode', $settings['brand_icon_mode'] ?: 'default')),
        iconShape: @js(old('brand_icon_shape', $settings['brand_icon_shape'] ?: 'rounded')),
        loginMode: @js(old('login_title_mode', $settings['login_title_mode'] ?: 'default')),
        loginText: @js(old('login_title_text', $settings['login_title_text'] ?? '')),
        headerMode: @js(old('header_title_mode', $settings['header_title_mode'] ?: 'default')),
        headerText: @js(old('header_title_text', $settings['header_title_text'] ?? '')),
        systemName: @js(old('system_name', $settings['system_name'] ?? '')),
        hasLogo: @js((bool) $logoPath),
        logoHeaderH: @js(old('logo_height_header', $settings['logo_height_header'] ?? '')),
        logoLoginH: @js(old('logo_height_login', $settings['logo_height_login'] ?? '')),
        logoUrl: @js($logoPath ? $storage->url($logoPath) : ''),
        get iconInitial() { return (this.systemName.trim()[0] || 'A').toUpperCase(); },
        get shapeClass() { return { rounded: 'rounded-md', circle: 'rounded-full', square: 'rounded-none' }[this.iconShape] || 'rounded-md'; },
        get loginPreview() {
            if (this.loginMode === 'hidden') return '';
            if (this.loginMode === 'custom') return this.loginText.trim();
            return this.systemName.trim() || 'System';
        },
        get headerPreview() {
            if (this.headerMode === 'hidden') return '';
            if (this.headerMode === 'custom') return this.headerText.trim();
            return this.systemName.trim() || 'System';
        },
    }"
    x-init="$nextTick(() => snapshot()); $watch('tab', v => { try { localStorage.setItem('idp_settings_tab', v); } catch (e) {} });
             window.addEventListener('beforeunload', e => { if (dirty && ! saving) { e.preventDefault(); e.returnValue = ''; } })"
    x-cloak
>
    {{-- Mobile: Abschnittswahl --}}
    <div class="mb-5 flex gap-2 overflow-x-auto pb-1 lg:hidden">
        @foreach ($sections as $s)
            <button type="button" @click="tab = '{{ $s['id'] }}'"
                    :class="tab === '{{ $s['id'] }}' ? 'bg-laravel-600 text-white' : 'border border-gray-200 bg-white text-gray-600'"
                    class="relative shrink-0 rounded-full px-3 py-1.5 text-sm font-medium transition">{{ $s['label'] }}
                @if ($errorSections->contains($s['id']))<span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-red-500"></span>@endif
            </button>
        @endforeach
    </div>

    <div class="lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-8">
        {{-- Desktop: Abschnittsnavigation --}}
        <nav class="hidden lg:block">
            <div class="space-y-1">
                @foreach ($sections as $s)
                    <button type="button" @click="tab = '{{ $s['id'] }}'"
                            :class="tab === '{{ $s['id'] }}' ? 'bg-laravel-50 text-laravel-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
                            class="flex w-full items-start gap-2.5 rounded-md px-3 py-2 text-left transition">
                        <x-icon name="{{ $s['icon'] }}" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5 text-sm font-medium">
                                {{ $s['label'] }}
                                @if ($errorSections->contains($s['id']))
                                    <span class="h-2 w-2 rounded-full bg-red-500" title="Eingabefehler"></span>
                                @elseif ($s['id'] === 'overview' && $openChecks > 0)
                                    <span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-semibold text-amber-700">{{ $openChecks }}</span>
                                @endif
                            </span>
                            <span class="block text-xs font-normal leading-snug text-gray-500">{{ $s['desc'] }}</span>
                            @if (! empty($sectionStatus[$s['id']]))
                                <x-badge :color="$sectionStatus[$s['id']][1]" class="mt-1 !px-2 !py-0 !text-[10px]">{{ $sectionStatus[$s['id']][0] }}</x-badge>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 space-y-6">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6"
                  x-ref="form" @input="updateChanges()" @change="updateChanges()" @click="$nextTick(() => updateChanges())" @submit="saving = true">
                @csrf
                @method('PUT')

                {{-- ===== Übersicht ===== --}}
                <div x-show="tab === 'overview'" class="space-y-6">
                    <x-card title="Zustand des Systems"
                            description="Hier sieht man auf einen Blick, was bereits eingerichtet ist und was noch zu tun ist.">
                        <ul class="divide-y divide-gray-100">
                            @foreach ($checks as $check)
                                <li class="flex items-start gap-3 py-4 first:pt-0 last:pb-0">
                                    @if ($check['ok'])
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" title="In Ordnung">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                        </span>
                                    @elseif (! empty($check['optional']))
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500" title="Optional">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4a1 1 0 0 1 1 1v4h4a1 1 0 1 1 0 2h-4v4a1 1 0 1 1-2 0v-4H5a1 1 0 1 1 0-2h4V5a1 1 0 0 1 1-1Z"/></svg>
                                        </span>
                                    @else
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700" title="Zu erledigen">
                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.6 3.2a1.6 1.6 0 0 1 2.8 0l6 10.5A1.6 1.6 0 0 1 16 16H4a1.6 1.6 0 0 1-1.4-2.3l6-10.5ZM10 7a1 1 0 0 0-1 1v3a1 1 0 1 0 2 0V8a1 1 0 0 0-1-1Zm0 7.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                                        </span>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900">
                                            {{ $check['title'] }}
                                            @if (! $check['ok'] && ! empty($check['optional']))
                                                <span class="ml-1 text-xs font-normal text-gray-400">optional</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-sm text-gray-500">{{ $check['text'] }}</p>
                                    </div>
                                    <x-button type="button" variant="secondary" size="sm" @click="tab = '{{ $check['section'] }}'; window.scrollTo({ top: 0, behavior: 'smooth' })">
                                        {{ $check['ok'] ? 'Öffnen' : $check['action'] }}
                                    </x-button>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>

                    <x-card title="So funktionieren die Einstellungen">
                        <ul class="space-y-2 text-sm text-gray-600">
                            <li><strong class="font-medium text-gray-900">Speichern:</strong> Änderungen in den Abschnitten Allgemein, Erscheinungsbild, Anmeldung, Sicherheit, E-Mail, Protokoll und Wartung werden erst mit „Speichern“ übernommen. Unten erscheint ein Hinweis, solange etwas ungespeichert ist.</li>
                            <li><strong class="font-medium text-gray-900">Bilder:</strong> Banner, Favicon und Login-Hintergrund werden sofort beim Hochladen gespeichert.</li>
                            <li><strong class="font-medium text-gray-900">Wirkung:</strong> Alles gilt sofort für alle Benutzer. Rot markierte Abschnitte in der Navigation enthalten Eingabefehler.</li>
                        </ul>
                    </x-card>
                </div>
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
                                <x-input type="text" name="timezone" list="timezone-list" value="{{ old('timezone', $settings['timezone']) }}" required />
                                <datalist id="timezone-list">
                                    @foreach (\DateTimeZone::listIdentifiers() as $tz)
                                        <option value="{{ $tz }}"></option>
                                    @endforeach
                                </datalist>
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
                                    <img :src="logoUrl" alt="" class="max-w-[12rem] object-contain" :style="`height:${(Number(logoHeaderH) || 32) * 0.875}px`">
                                </template>
                                <template x-if="! hasLogo && iconMode !== 'hidden'">
                                    <span class="flex h-7 w-7 items-center justify-center text-white" :class="shapeClass" :style="`background:${accent}`">
                                        <span x-show="iconMode === 'initial'" class="text-xs font-semibold" x-text="iconInitial"></span>
                                        <svg x-show="iconMode !== 'initial'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                                    </span>
                                </template>
                                <span class="truncate text-sm font-semibold text-gray-800" x-show="headerPreview" x-text="headerPreview"></span>
                                <span class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium text-white" :style="`background:${accent}`">Administration</span>
                            </div>
                            {{-- Anmeldeseite --}}
                            <div class="flex flex-col items-center gap-2 bg-gray-50 px-3 py-6">
                                <template x-if="hasLogo">
                                    <img :src="logoUrl" alt="" class="max-w-full object-contain" :style="`height:${(Number(logoLoginH) || 56) * 0.8}px`">
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

                    <x-card title="Banner-Größe" description="Skaliert ein hochgeladenes Banner (auch SVG). Die Breite folgt dem Seitenverhältnis. Leer lassen für die Standardgröße.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Höhe im Kopfbereich" hint="In Pixeln, 16 bis 96. Standard: 32.">
                                <x-input type="number" name="logo_height_header" min="16" max="96" step="1" x-model="logoHeaderH"
                                         value="{{ old('logo_height_header', $settings['logo_height_header']) }}" placeholder="32" class="!w-28" />
                                @error('logo_height_header')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </x-setting-row>
                            <x-setting-row label="Höhe auf der Anmeldeseite" hint="In Pixeln, 24 bis 240. Standard: bis zu 56.">
                                <x-input type="number" name="logo_height_login" min="24" max="240" step="1" x-model="logoLoginH"
                                         value="{{ old('logo_height_login', $settings['logo_height_login']) }}" placeholder="56" class="!w-28" />
                                @error('logo_height_login')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </x-setting-row>
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

                            <x-setting-row label="Layout der Anmeldeseite" hint="„Geteilt&quot; zeigt links eine große Fläche mit Marke und Hintergrundbild, rechts das Formular. Ohne Hintergrundbild wird dort ein Farbverlauf in der Akzentfarbe angezeigt.">
                                <x-select name="login_layout" class="!w-64">
                                    <option value="centered" @selected(old('login_layout', $settings['login_layout'] ?: 'centered') === 'centered')>Zentriert (Standard)</option>
                                    <option value="split" @selected(old('login_layout', $settings['login_layout'] ?: 'centered') === 'split')>Geteilt</option>
                                </x-select>
                            </x-setting-row>

                            <x-setting-row label="Titel im Kopfbereich" hint="Der Text neben dem Symbol in der Administration und im Portal.">
                                <div class="space-y-2">
                                    <x-select name="header_title_mode" x-model="headerMode" class="!w-64">
                                        <option value="default">Systemnamen anzeigen</option>
                                        <option value="hidden">Ausblenden</option>
                                        <option value="custom">Eigener Text</option>
                                    </x-select>
                                    <x-input type="text" name="header_title_text" maxlength="255" x-model="headerText"
                                             x-show="headerMode === 'custom'"
                                             value="{{ old('header_title_text', $settings['header_title_text']) }}"
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

                    <x-card title="Zwei-Faktor: vertraute Geräte und Geräte-Meldung"
                            description="Steuert das Merken von Geräten auf der Zwei-Faktor-Seite und die einmalige E-Mail bei Anmeldung von einem neuen Gerät.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Vertrautes Gerät anbieten"
                                           hint="Zeigt auf der Zwei-Faktor-Seite die Option zum Merken des Geräts. Der zweite Faktor wird auf diesem Gerät dann eine Weile nicht mehr abgefragt.">
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                    <input type="hidden" name="trusted_device_enabled" value="0">
                                    <x-checkbox name="trusted_device_enabled" value="1"
                                                :checked="old('trusted_device_enabled', $settings['trusted_device_enabled']) !== '0'" />
                                    Option auf der Zwei-Faktor-Seite anzeigen
                                </label>
                            </x-setting-row>
                            <x-setting-row label="Gültigkeit eines vertrauten Geräts">
                                <div class="flex items-center gap-2">
                                    <x-input type="number" name="trusted_device_days" min="1" max="365" class="!w-24"
                                             value="{{ old('trusted_device_days', $settings['trusted_device_days'] ?: '120') }}" />
                                    <span class="text-sm text-gray-500">Tage</span>
                                </div>
                            </x-setting-row>
                            <x-setting-row label="E-Mail bei Anmeldung von neuem Gerät"
                                           hint="Einmalig je neuer Kombination aus Browser, Betriebssystem und Gerätetyp. Bekannte Geräte lösen nichts aus, es wird nichts abgemeldet.">
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                    <input type="hidden" name="new_device_email_enabled" value="0">
                                    <x-checkbox name="new_device_email_enabled" value="1"
                                                :checked="old('new_device_email_enabled', $settings['new_device_email_enabled']) !== '0'" />
                                    Benutzer per E-Mail informieren
                                </label>
                            </x-setting-row>
                        </div>
                    </x-card>

                    <x-card title="Passkeys (WebAuthn / FIDO2)"
                            description="Passkeys richtet jeder Benutzer selbst unter Profil, Sicherheit ein – als zweiter Faktor und, wenn hier erlaubt, für die passwortlose Anmeldung.">
                        <div class="divide-y divide-gray-100">
                            <x-setting-row label="Passwortlose Anmeldung (lokale Konten)"
                                           hint="Erlaubt lokalen Konten, sich allein mit einem Passkey anzumelden.">
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                    <input type="hidden" name="passkey_passwordless_local_enabled" value="0">
                                    <x-checkbox name="passkey_passwordless_local_enabled" value="1"
                                                :checked="old('passkey_passwordless_local_enabled', $settings['passkey_passwordless_local_enabled']) !== '0'" />
                                    Passwortlose Anmeldung mit Passkey erlauben
                                </label>
                            </x-setting-row>
                            <x-setting-row label="Passwortlose Anmeldung (Active-Directory-Konten)"
                                           hint="Vorsicht: Damit umgeht ein AD-Konto sein Active-Directory-Passwort. Standard: aus.">
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700">
                                    <input type="hidden" name="passkey_passwordless_ad_enabled" value="0">
                                    <x-checkbox name="passkey_passwordless_ad_enabled" value="1"
                                                :checked="old('passkey_passwordless_ad_enabled', $settings['passkey_passwordless_ad_enabled']) === '1'" />
                                    Passwortlose Anmeldung mit Passkey für AD-Konten erlauben
                                </label>
                            </x-setting-row>
                        </div>
                    </x-card>
                </div>

                {{-- ===== E-Mail ===== --}}
                <div x-show="tab === 'email'">
                    <x-card title="E-Mail-Versand (SMTP)"
                            description="Für Systemnachrichten wie Benachrichtigungen. Ohne Konfiguration versendet das System keine E-Mails.">
                        <div class="space-y-4"
                             x-data="{ on: {{ old('mail_enabled', $settings['mail_enabled'] ?? '0') === '1' ? 'true' : 'false' }} }">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="hidden" name="mail_enabled" value="0">
                                <x-checkbox name="mail_enabled" value="1" class="mt-0.5" x-model="on" />
                                <span class="text-sm font-medium text-gray-900">E-Mail-Versand aktiv</span>
                            </label>

                            <div class="divide-y divide-gray-100" x-show="on" x-cloak>
                                <x-setting-row label="SMTP-Server" hint="Hostname des Postausgangsservers, z. B. <code>smtp.firma.de</code>.">
                                    <x-input type="text" name="mail_host" value="{{ old('mail_host', $settings['mail_host']) }}" placeholder="smtp.firma.de" />
                                </x-setting-row>
                                <x-setting-row label="Port">
                                    <x-input type="number" name="mail_port" min="1" max="65535"
                                             value="{{ old('mail_port', $settings['mail_port'] ?: '587') }}" class="!w-28" />
                                </x-setting-row>
                                <x-setting-row label="Verschlüsselung">
                                    @php($enc = old('mail_encryption', $settings['mail_encryption'] ?: 'starttls'))
                                    <x-select name="mail_encryption" class="!w-48">
                                        <option value="starttls" @selected($enc === 'starttls')>STARTTLS (Port 587)</option>
                                        <option value="ssl" @selected($enc === 'ssl')>SSL/TLS (Port 465)</option>
                                        <option value="none" @selected($enc === 'none')>Keine</option>
                                    </x-select>
                                </x-setting-row>
                                <x-setting-row label="Benutzername" hint="Meist die vollständige Absenderadresse. Leer lassen, wenn der Server keine Anmeldung verlangt.">
                                    <x-input type="text" name="mail_username" autocomplete="off"
                                             value="{{ old('mail_username', $settings['mail_username']) }}" />
                                </x-setting-row>
                                <x-setting-row label="Passwort">
                                    <x-input type="password" name="mail_password" autocomplete="new-password"
                                             placeholder="{{ $hasMailPassword ? '•••••••• (unverändert lassen)' : '' }}" />
                                </x-setting-row>
                                <x-setting-row label="Absenderadresse" hint="Erscheint als Absender in versendeten E-Mails.">
                                    <x-input type="email" name="mail_from_address"
                                             value="{{ old('mail_from_address', $settings['mail_from_address']) }}" placeholder="no-reply@firma.de" />
                                </x-setting-row>
                                <x-setting-row label="Absendername">
                                    <x-input type="text" name="mail_from_name"
                                             value="{{ old('mail_from_name', $settings['mail_from_name']) }}" placeholder="{{ $settings['system_name'] }}" />
                                </x-setting-row>
                            </div>
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

                {{-- Speicherleiste (nicht bei "Übersicht" und "Bilder") --}}
                <div x-show="tab !== 'images' && tab !== 'overview'"
                     class="sticky bottom-0 z-10 -mx-4 sm:mx-0">
                    {{-- Was wurde verändert? --}}
                    <div x-show="dirty && showChanges" x-cloak x-transition
                         class="mb-2 max-h-64 overflow-y-auto border border-gray-200 bg-white px-4 py-3 shadow-lg sm:rounded-lg">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Das hat sich geändert</p>
                        <ul class="divide-y divide-gray-100 text-sm">
                            <template x-for="c in changes" :key="c.i">
                                <li class="flex flex-wrap items-baseline gap-x-2 py-1.5">
                                    <span class="font-medium text-gray-900" x-text="c.label"></span>
                                    <span class="text-gray-500">
                                        <template x-if="! c.secret"><span><span x-text="c.from"></span> <span aria-hidden="true">→</span> <span class="font-medium text-amber-700" x-text="c.to"></span></span></template>
                                        <template x-if="c.secret"><span class="font-medium text-amber-700" x-text="c.to"></span></template>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 bg-gray-100 px-4 py-3 sm:rounded-lg sm:border sm:bg-white">
                        <x-button type="submit" x-bind:class="dirty ? '' : 'opacity-60'">Speichern</x-button>
                        <button type="button" x-show="dirty" x-cloak onclick="window.location.reload()"
                                class="text-sm text-gray-500 underline decoration-dotted hover:text-gray-700">Verwerfen</button>
                        <span x-show="dirty" x-cloak class="flex items-center gap-1.5 text-sm font-medium text-amber-700">
                            <span class="h-2 w-2 rounded-full bg-amber-500"></span>Ungespeicherte Änderungen
                            (<span x-text="changes.length"></span>)
                        </span>
                        <button type="button" x-show="dirty" x-cloak @click="showChanges = ! showChanges"
                                class="text-sm text-laravel-600 hover:text-laravel-700"
                                x-text="showChanges ? 'Details ausblenden' : 'Was wurde geändert?'"></button>
                        <span x-show="! dirty" class="text-sm text-gray-500">Keine ungespeicherten Änderungen.</span>
                    </div>
                </div>
            </form>

            {{-- ===== E-Mail: Testnachricht (eigenes Formular) ===== --}}
            <div x-show="tab === 'email'">
                <x-card title="Testnachricht" description="Prüft die oben gespeicherten Zugangsdaten. Vorher speichern.">
                    <form method="POST" action="{{ route('admin.settings.test-mail') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div class="min-w-[16rem] flex-1">
                            <x-input-label value="Empfänger" />
                            <x-input type="email" name="test_email" required
                                     value="{{ old('test_email', auth()->user()->email) }}" placeholder="du@firma.de" />
                        </div>
                        <x-button type="submit" variant="secondary" :disabled="! $mailConfigured">
                            <x-icon name="mail" class="h-4 w-4" />Testnachricht senden
                        </x-button>
                    </form>
                    @unless ($mailConfigured)
                        <p class="mt-2 text-xs text-amber-600">SMTP ist noch nicht aktiv gespeichert.</p>
                    @endunless
                </x-card>
            </div>

            {{-- ===== Bilder (eigene Formulare, außerhalb des Einstellungsformulars) ===== --}}
            <div x-show="tab === 'images'" class="space-y-6">
                <x-card title="Banner (Logo)"
                        description="Ersetzt das Symbol im Kopfbereich und auf der Anmeldeseite. Am besten ein PNG oder SVG mit durchsichtigem Hintergrund.">
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
                            <x-image-upload-input name="logo" required />
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
                            <x-image-upload-input name="favicon" accept="image/*,.ico,image/x-icon,image/vnd.microsoft.icon" preview-class="h-10 w-10" required />
                            <p class="mt-1 text-xs text-gray-400">PNG, WebP, SVG oder ICO.</p>
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
                            <x-image-upload-input name="login_background" preview-class="max-h-40 w-full object-cover" required />
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
