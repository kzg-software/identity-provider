@extends('layouts.admin')

@section('admin-content')
<div class="max-w-2xl">
    <h1 class="text-2xl font-semibold text-gray-900">Systemeinstellungen</h1>
    <p class="mt-1 text-sm text-gray-500">Hier stellst du Grunddaten, Aussehen und Anmeldung des Systems ein. Änderungen wirken sofort für alle Benutzer.</p>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('PUT')

        {{-- ------------------------------------------------------------------ --}}
        <x-card title="Grunddaten">
            <div class="space-y-4">
                <div>
                    <x-input-label value="Systemname" />
                    <x-input type="text" name="system_name" value="{{ old('system_name', $settings['system_name']) }}" required />
                    <p class="text-xs text-gray-500 mt-1">Erscheint im Kopfbereich, im Browser-Tab und auf der Anmeldeseite.</p>
                </div>
                <div>
                    <x-input-label value="Basis-URL" />
                    <x-input type="url" name="base_url" value="{{ old('base_url', $settings['base_url']) }}" required />
                    <p class="text-xs text-gray-500 mt-1">Die Web-Adresse, unter der das System erreichbar ist, z. B. <code>https://login.firma.de</code>.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label value="Zeitzone" />
                        <x-input type="text" name="timezone" value="{{ old('timezone', $settings['timezone']) }}" required />
                        <p class="text-xs text-gray-500 mt-1">z. B. <code>Europe/Berlin</code>.</p>
                    </div>
                    <div>
                        <x-input-label value="Sprache" />
                        <x-input type="text" name="locale" value="{{ old('locale', $settings['locale']) }}" required />
                        <p class="text-xs text-gray-500 mt-1">Sprachkürzel, z. B. <code>de</code>.</p>
                    </div>
                </div>
                <div>
                    <x-input-label value="Automatische Abmeldung nach (Minuten)" />
                    <x-input type="number" name="session_lifetime" value="{{ old('session_lifetime', $settings['session_lifetime']) }}" required />
                    <p class="text-xs text-gray-500 mt-1">Nach dieser Zeit ohne Aktivität muss man sich neu anmelden.</p>
                </div>
            </div>
        </x-card>

        {{-- ------------------------------------------------------------------ --}}
        <x-card title="Aussehen">
            <div class="space-y-6">
                {{-- Akzentfarbe --}}
                <div x-data="{ c: '{{ old('accent_color', $settings['accent_color'] ?: \App\Support\AccentPalette::DEFAULT) }}' }">
                    <x-input-label value="Akzentfarbe" />
                    <p class="text-xs text-gray-500 mb-2">Wird systemweit für Schaltflächen, Links und Hervorhebungen verwendet.</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="color" x-model="c" aria-label="Farbe wählen"
                               class="h-9 w-12 shrink-0 rounded border border-gray-300 bg-white p-1 cursor-pointer">
                        <x-input type="text" name="accent_color" x-model="c" maxlength="7"
                                 value="{{ old('accent_color', $settings['accent_color'] ?: \App\Support\AccentPalette::DEFAULT) }}"
                                 class="!w-32 font-mono uppercase" />
                        <div class="flex gap-1.5">
                            <template x-for="p in ['#FF2D20','#2563EB','#059669','#7C3AED','#DB2777','#EA580C','#0891B2','#475569']" :key="p">
                                <button type="button" @click="c = p" :style="`background:${p}`" :title="p"
                                        class="h-6 w-6 rounded-full border border-black/10 ring-offset-1"
                                        :class="c.toUpperCase() === p ? 'ring-2 ring-gray-400' : ''"></button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Symbol / Icon --}}
                <div class="border-t border-gray-200 pt-5"
                     x-data="{
                        mode: '{{ old('brand_icon_mode', $settings['brand_icon_mode'] ?: 'default') }}',
                        shape: '{{ old('brand_icon_shape', $settings['brand_icon_shape'] ?: 'rounded') }}',
                        initial: @js(\Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(trim((string) ($settings['system_name'] ?? '')), 0, 1)) ?: 'A')
                     }">
                    <x-input-label value="Symbol (Icon)" />
                    <p class="text-xs text-gray-500 mb-3">Das kleine Zeichen links oben im Kopfbereich und über dem Anmeldeformular. Sobald weiter unten ein Banner hochgeladen ist, wird dieses statt des Symbols angezeigt.</p>

                    <div class="flex flex-wrap items-start gap-4">
                        <div class="min-w-[14rem] flex-1">
                            <x-input-label value="Darstellung" class="!text-xs !font-normal !text-gray-500" />
                            <x-select name="brand_icon_mode" x-model="mode">
                                <option value="default">Standard-Zeichen (Schild)</option>
                                <option value="initial">Anfangsbuchstabe des Systemnamens</option>
                                <option value="hidden">Ausblenden</option>
                            </x-select>
                        </div>
                        <div class="min-w-[14rem] flex-1" x-show="mode !== 'hidden'" x-cloak>
                            <x-input-label value="Form" class="!text-xs !font-normal !text-gray-500" />
                            <x-select name="brand_icon_shape" x-model="shape">
                                <option value="rounded">Abgerundet (wie jetzt)</option>
                                <option value="circle">Rund</option>
                                <option value="square">Eckig</option>
                            </x-select>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-3">
                        <span class="text-xs text-gray-500">Vorschau:</span>
                        <span x-show="mode === 'hidden'" x-cloak class="text-xs text-gray-400">Kein Symbol</span>
                        <span x-show="mode !== 'hidden'"
                              class="flex items-center justify-center h-10 w-10 bg-laravel-600 text-white"
                              :class="{ 'rounded-md': shape === 'rounded', 'rounded-full': shape === 'circle', 'rounded-none': shape === 'square' }">
                            <span x-show="mode === 'initial'" class="font-semibold" x-text="initial"></span>
                            <svg x-show="mode !== 'initial'" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                        </span>
                    </div>
                </div>

                {{-- Titel auf der Anmeldeseite --}}
                <div class="border-t border-gray-200 pt-5"
                     x-data="{ mode: '{{ old('login_title_mode', $settings['login_title_mode'] ?: 'default') }}' }">
                    <x-input-label value="Titel auf der Anmeldeseite" />
                    <p class="text-xs text-gray-500 mb-2">Der Text unter dem Symbol über dem Anmeldeformular.</p>
                    <x-select name="login_title_mode" x-model="mode">
                        <option value="default">Systemnamen anzeigen</option>
                        <option value="hidden">Ausblenden</option>
                        <option value="custom">Eigener Text</option>
                    </x-select>
                    <div x-show="mode === 'custom'" x-cloak class="mt-3">
                        <x-input-label value="Eigener Text" class="!text-xs !font-normal !text-gray-500" />
                        <x-input type="text" name="login_title_text" maxlength="255"
                                 value="{{ old('login_title_text', $settings['login_title_text']) }}"
                                 placeholder="z. B. Willkommen" />
                    </div>
                </div>
            </div>
        </x-card>

        {{-- ------------------------------------------------------------------ --}}
        <x-card title="Windows-Anmeldung (Single Sign-On)">
            <p class="text-xs text-gray-500">Ist sie aktiv, werden Benutzer automatisch über ihr Windows-Konto angemeldet, sobald der Webserver die Identität liefert. Ist sie aus, erscheint für alle die normale Anmeldeseite, auch wenn der Webserver Windows-Authentifizierung macht. Lokale Konten sind davon nicht betroffen.</p>
            <label class="mt-3 flex items-start gap-3 cursor-pointer">
                <input type="hidden" name="windows_sso_enabled" value="0">
                <x-checkbox name="windows_sso_enabled" value="1" class="mt-0.5" :checked="old('windows_sso_enabled', $settings['windows_sso_enabled'] ?? '1') !== '0'" />
                <span class="text-sm font-medium text-gray-900">Automatische Windows-Anmeldung aktiv</span>
            </label>
        </x-card>

        {{-- ------------------------------------------------------------------ --}}
        <x-card title="Wartungsmodus (gesamtes System)">
            <p class="text-xs text-gray-500">Ist er aktiv, sieht jeder eine Wartungsseite. Lokale Administratoren und die unten freigegebenen Benutzer kommen weiterhin rein; die Anmeldeseite bleibt für alle erreichbar.</p>

            <label class="mt-3 flex items-start gap-3 cursor-pointer">
                <input type="hidden" name="maintenance_mode" value="0">
                <x-checkbox name="maintenance_mode" value="1" class="mt-0.5" :checked="old('maintenance_mode', $settings['maintenance_mode']) === '1'" />
                <span class="text-sm font-medium text-gray-900">Wartungsmodus jetzt aktivieren</span>
            </label>

            <div class="mt-4">
                <x-input-label value="Wartungsmeldung" />
                <x-textarea name="maintenance_message" rows="2" placeholder="Das System wird zurzeit gewartet. Bitte versuchen Sie es später erneut.">{{ old('maintenance_message', $settings['maintenance_message']) }}</x-textarea>
            </div>

            <div class="mt-4">
                <x-input-label value="Wer trotzdem rein darf" />
                <p class="text-xs text-gray-500 mb-1">Ein Eintrag pro Zeile: Benutzername oder <code>@Gruppenname</code>. Lokale Administratoren haben immer Zugriff.</p>
                <x-textarea name="maintenance_allow" rows="3" placeholder="mmustermann&#10;@IT-Abteilung">{{ old('maintenance_allow', $settings['maintenance_allow']) }}</x-textarea>
            </div>
        </x-card>

        <div class="flex justify-end">
            <x-button type="submit">Speichern</x-button>
        </div>
    </form>

    {{-- ---------------------------------------------------------------------- --}}
    <div class="mt-10">
        <h2 class="text-lg font-semibold text-gray-900">Bilder</h2>
        <p class="mt-1 text-sm text-gray-500">Diese werden einzeln gespeichert – ein Klick auf „Hochladen“ bzw. „Entfernen“ genügt, der große „Speichern“-Knopf oben ist dafür nicht nötig.</p>

        <div class="mt-4 space-y-6">
            <x-card title="Banner (Logo)">
                <p class="text-sm text-gray-500 mb-4">Ersetzt das Symbol im Kopfbereich aller Seiten und auf der Anmeldeseite. Am besten ein Bild mit durchsichtigem Hintergrund (PNG oder SVG).</p>

                @if ($logoPath)
                    <div class="flex items-center gap-4 mb-4">
                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Aktuelles Banner" class="h-12 max-w-xs object-contain border border-gray-200 rounded-md p-2">
                        <x-confirm-form :action="route('admin.settings.logo.delete')" message="Banner wirklich entfernen?" label="Entfernen" size="sm" />
                    </div>
                @else
                    <p class="text-sm text-gray-400 mb-4">Kein Banner hochgeladen — es wird das oben eingestellte Symbol angezeigt.</p>
                @endif

                <form method="POST" action="{{ route('admin.settings.logo.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label value="Neues Banner hochladen" />
                        <input type="file" name="logo" accept="image/*" required class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
                    </div>
                    <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                </form>
            </x-card>

            <x-card title="Favicon">
                <p class="text-sm text-gray-500 mb-4">Das kleine Symbol im Browser-Tab.</p>

                @if ($faviconPath)
                    <div class="flex items-center gap-4 mb-4">
                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($faviconPath) }}" alt="Aktuelles Favicon" class="h-8 w-8 object-contain border border-gray-200 rounded-md p-1">
                        <x-confirm-form :action="route('admin.settings.favicon.delete')" message="Favicon wirklich entfernen?" label="Entfernen" size="sm" />
                    </div>
                @else
                    <p class="text-sm text-gray-400 mb-4">Kein Favicon hochgeladen.</p>
                @endif

                <form method="POST" action="{{ route('admin.settings.favicon.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label value="Neues Favicon hochladen" />
                        <input type="file" name="favicon" accept="image/*,.ico,image/x-icon,image/vnd.microsoft.icon" required class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
                        <p class="text-xs text-gray-400 mt-1">PNG, SVG, WebP oder ICO.</p>
                    </div>
                    <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                </form>
            </x-card>

            <x-card title="Login-Hintergrund">
                <p class="text-sm text-gray-500 mb-4">Vollflächiges Hintergrundbild der Anmeldeseite. Ist keines hinterlegt, bleibt der Hintergrund schlicht (wie aktuell).</p>

                @if ($loginBackgroundPath)
                    <div class="mb-4">
                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($loginBackgroundPath) }}" alt="Aktueller Login-Hintergrund" class="w-full max-h-48 object-cover rounded-md border border-gray-200">
                        <div class="mt-3">
                            <x-confirm-form :action="route('admin.settings.login-background.delete')" message="Login-Hintergrund wirklich entfernen?" label="Entfernen" size="sm" />
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400 mb-4">Kein Hintergrundbild — die Anmeldeseite zeigt einen neutralen Hintergrund.</p>
                @endif

                <form method="POST" action="{{ route('admin.settings.login-background.upload') }}" enctype="multipart/form-data" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label value="Neues Hintergrundbild hochladen" />
                        <input type="file" name="login_background" accept="image/*" required class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
                        <p class="text-xs text-gray-400 mt-1">Empfohlen: breites Bild (z. B. 1920×1080), max. 8 MB.</p>
                    </div>
                    <x-button type="submit" variant="secondary" size="sm">Hochladen</x-button>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
