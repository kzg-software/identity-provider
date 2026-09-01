@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-6">Systemeinstellungen</h1>

<div class="space-y-6 max-w-xl">
    <x-card title="Allgemein">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <x-input-label value="Systemname" />
                <x-input type="text" name="system_name" value="{{ old('system_name', $settings['system_name']) }}" required />
            </div>
            <div>
                <x-input-label value="Basis-URL" />
                <x-input type="url" name="base_url" value="{{ old('base_url', $settings['base_url']) }}" required />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label value="Zeitzone" />
                    <x-input type="text" name="timezone" value="{{ old('timezone', $settings['timezone']) }}" required />
                </div>
                <div>
                    <x-input-label value="Sprache" />
                    <x-input type="text" name="locale" value="{{ old('locale', $settings['locale']) }}" required />
                </div>
            </div>
            <div>
                <x-input-label value="Session-Dauer (Minuten)" />
                <x-input type="number" name="session_lifetime" value="{{ old('session_lifetime', $settings['session_lifetime']) }}" required />
            </div>

            <div x-data="{ c: '{{ old('accent_color', $settings['accent_color'] ?: \App\Support\AccentPalette::DEFAULT) }}' }">
                <x-input-label value="Akzentfarbe" />
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
                <p class="text-xs text-gray-500 mt-1">Wird systemweit für Buttons, Links und Hervorhebungen verwendet. Leer lassen für den Standard.</p>
            </div>

            <div class="border-t border-gray-200 pt-4 space-y-3" x-data="{ mode: '{{ old('login_title_mode', $settings['login_title_mode'] ?: 'default') }}' }">
                <h4 class="text-sm font-semibold text-gray-900">Anmeldeseite</h4>
                <div>
                    <x-input-label value="Titel unter dem Logo" />
                    <x-select name="login_title_mode" x-model="mode">
                        <option value="default">Systemnamen anzeigen</option>
                        <option value="hidden">Ausblenden</option>
                        <option value="custom">Eigener Text</option>
                    </x-select>
                </div>
                <div x-show="mode === 'custom'" x-cloak>
                    <x-input-label value="Eigener Text" />
                    <x-input type="text" name="login_title_text" maxlength="255"
                             value="{{ old('login_title_text', $settings['login_title_text']) }}"
                             placeholder="z. B. Willkommen" />
                </div>
            </div>

            <div class="border-t border-gray-200 pt-4 space-y-4">
                <h4 class="text-sm font-semibold text-gray-900">Wartungsmodus (gesamtes System)</h4>
                <p class="text-xs text-gray-500 -mt-2">Ist er aktiv, sieht jeder eine Wartungsseite. Lokale Administratoren und die unten freigegebenen Benutzer kommen weiterhin rein; die Anmeldeseite bleibt für alle erreichbar.</p>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="maintenance_mode" value="0">
                    <x-checkbox name="maintenance_mode" value="1" class="mt-0.5" :checked="old('maintenance_mode', $settings['maintenance_mode']) === '1'" />
                    <span class="text-sm font-medium text-gray-900">Wartungsmodus jetzt aktivieren</span>
                </label>

                <div>
                    <x-input-label value="Wartungsmeldung" />
                    <x-textarea name="maintenance_message" rows="2" placeholder="Das System wird zurzeit gewartet. Bitte versuchen Sie es später erneut.">{{ old('maintenance_message', $settings['maintenance_message']) }}</x-textarea>
                </div>

                <div>
                    <x-input-label value="Wer trotzdem rein darf" />
                    <p class="text-xs text-gray-500 mb-1">Ein Eintrag pro Zeile: Benutzername oder <code>@Gruppenname</code>. Lokale Administratoren haben immer Zugriff.</p>
                    <x-textarea name="maintenance_allow" rows="3" placeholder="mmustermann&#10;@IT-Abteilung">{{ old('maintenance_allow', $settings['maintenance_allow']) }}</x-textarea>
                </div>
            </div>

            <x-button type="submit">Speichern</x-button>
        </form>
    </x-card>

    <x-card title="Banner (Logo)">
        <p class="text-sm text-gray-500 mb-4">Wird im Header aller Seiten und auf der Login-Seite anstelle des Standard-Symbols angezeigt.</p>

        @if ($logoPath)
            <div class="flex items-center gap-4 mb-4">
                <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Aktuelles Banner" class="h-12 max-w-xs object-contain border border-gray-200 rounded-md p-2">
                <x-confirm-form :action="route('admin.settings.logo.delete')" message="Banner wirklich entfernen?" label="Entfernen" size="sm" />
            </div>
        @else
            <p class="text-sm text-gray-400 mb-4">Kein Banner hochgeladen — es wird das Standard-Symbol angezeigt.</p>
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
        <p class="text-sm text-gray-500 mb-4">Wird als Browser-Tab-Symbol verwendet.</p>

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
                <input type="file" name="favicon" accept="image/*" required class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-laravel-50 file:text-laravel-700 hover:file:bg-laravel-100">
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
@endsection
