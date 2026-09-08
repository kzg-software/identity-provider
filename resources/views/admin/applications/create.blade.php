@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Anwendung anlegen"
    :back="route('admin.applications.index')" back-label="Alle Anwendungen"
    description="In wenigen Schritten: zuerst die Anwendung fürs Portal, dann optional ein Provider für die eigentliche Anmeldung (OAuth 2.0 / OpenID Connect oder SAML 2.0)." />

<form method="POST" action="{{ route('admin.applications.store') }}"
      x-data="applicationWizard({{ Js::from([
          'step' => old('provider_mode') ? 2 : 1,
          'providerMode' => old('provider_mode', 'none'),
          'name' => old('name', ''),
          'visibility' => old('visibility', 'portal'),
      ]) }})"
      enctype="multipart/form-data" class="space-y-6">
    @csrf
    <input type="hidden" name="provider_mode" :value="providerMode">

    {{-- Fortschritt --}}
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium text-gray-400">
        <template x-for="(label, i) in stepLabels" :key="i">
            <li class="flex items-center gap-2">
                <span class="flex h-5 w-5 items-center justify-center rounded-full border"
                      :class="visibleStep >= i + 1 ? 'border-laravel-600 bg-laravel-600 text-white' : 'border-gray-300'"
                      x-text="i + 1"></span>
                <span :class="visibleStep === i + 1 && 'text-laravel-700'" x-text="label"></span>
                <span x-show="i < stepLabels.length - 1" class="text-gray-300">/</span>
            </li>
        </template>
    </ol>

    {{-- Schritt 1: Anwendung --}}
    <div x-show="step === 1" x-cloak>
        <x-card title="1. Anwendung">
            <div class="space-y-4">
                <div>
                    <x-input-label value="Name" />
                    <x-input type="text" name="name" x-model="name" value="{{ old('name') }}" autofocus />
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-input-label value="Beschreibung (optional)" />
                    <x-textarea name="description">{{ old('description') }}</x-textarea>
                </div>
                <div>
                    <x-input-label value="Bereich (optional)" />
                    <x-input type="text" name="category" list="category-suggestions" value="{{ old('category') }}" placeholder="z. B. Allgemein" />
                    <datalist id="category-suggestions">
                        @foreach ($categories as $category)<option value="{{ $category }}">@endforeach
                    </datalist>
                    <p class="mt-1 text-xs text-gray-500">Fasst Anwendungen im Portal zu Bereichen zusammen. Gleicher Name gruppiert sie.</p>
                </div>
                <div>
                    <x-input-label>Start-URL (optional)
                        <x-field-info example="https://app.example.de">Erscheint Benutzern im Portal als Kachel zum Öffnen der Anwendung.</x-field-info>
                    </x-input-label>
                    <x-input type="url" name="launch_url" value="{{ old('launch_url') }}" placeholder="https://app.example.de" />
                </div>
                <div>
                    <x-input-label>Sichtbarkeit
                        <x-field-info>„Portal“: erscheint im Benutzerportal. „Verborgen“: nur über direkten Link/SSO nutzbar, keine Kachel.</x-field-info>
                    </x-input-label>
                    <x-select name="visibility" x-model="visibility">
                        <option value="portal">Im Benutzerportal anzeigen</option>
                        <option value="hidden">Verborgen (nur direkter Zugriff / SSO)</option>
                    </x-select>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Schritt 2: Provider? --}}
    <div x-show="step === 2" x-cloak>
        <x-card title="2. Provider hinzufügen?"
                description="Ein Provider regelt die technische Anmeldung. Ohne Provider ist die Anwendung nur eine Portal-Kachel – ein Provider lässt sich jederzeit später zuweisen.">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    'none' => ['Kein Provider', 'Nur die Anwendung anlegen.'],
                    'oidc' => ['OAuth 2.0 / OpenID Connect', 'Für moderne Web-, SPA- und Mobile-Apps.'],
                    'saml' => ['SAML 2.0', 'Für klassische Enterprise-Anwendungen.'],
                ] as $value => [$label, $hint])
                    <label class="cursor-pointer rounded-lg border p-4 transition"
                           :class="providerMode === '{{ $value }}' ? 'border-laravel-500 ring-1 ring-laravel-500 bg-laravel-50' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'">
                        <input type="radio" class="sr-only" value="{{ $value }}" x-model="providerMode">
                        <span class="block text-sm font-semibold text-gray-900">{{ $label }}</span>
                        <span class="mt-1 block text-xs text-gray-500">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
        </x-card>
    </div>

    {{-- Schritt 3: Provider konfigurieren --}}
    <div x-show="step === 3" x-cloak>
        <x-card x-show="providerMode === 'oidc'" title="3. OAuth 2.0 / OpenID Connect konfigurieren">
            @include('admin.providers._oidc-fields', ['required' => false])
        </x-card>
        <x-card x-show="providerMode === 'saml'" title="3. SAML 2.0 konfigurieren">
            @include('admin.providers._saml-fields', ['required' => false])
        </x-card>
    </div>

    {{-- Schritt 4: Zusammenfassung --}}
    <div x-show="step === 4" x-cloak>
        <x-card title="Zusammenfassung">
            <dl class="divide-y divide-gray-100 text-sm">
                <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-[minmax(0,11rem)_1fr]">
                    <dt class="text-gray-500">Anwendung</dt>
                    <dd class="text-gray-900" x-text="name || '–'"></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-[minmax(0,11rem)_1fr]">
                    <dt class="text-gray-500">Sichtbarkeit</dt>
                    <dd class="text-gray-900" x-text="visibility === 'portal' ? 'Im Benutzerportal' : 'Verborgen'"></dd>
                </div>
                <div class="grid grid-cols-1 gap-1 py-2 sm:grid-cols-[minmax(0,11rem)_1fr]">
                    <dt class="text-gray-500">Provider</dt>
                    <dd class="text-gray-900" x-text="{ none: 'Kein Provider', oidc: 'OAuth 2.0 / OpenID Connect', saml: 'SAML 2.0' }[providerMode]"></dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-gray-500" x-show="providerMode === 'oidc'">
                Nach dem Erstellen wird das Client Secret <strong>einmalig</strong> angezeigt – bitte sofort sichern.
            </p>
        </x-card>
    </div>

    {{-- Navigation --}}
    <div class="sticky bottom-0 -mx-4 flex items-center gap-3 border-t border-gray-200 bg-gray-100 px-4 py-3 sm:mx-0 sm:rounded-lg sm:border sm:bg-white sm:px-4">
        <x-button type="button" variant="secondary" size="sm" x-show="step > 1" @click="back()">Zurück</x-button>
        <x-button type="button" size="sm" x-show="step < 4" @click="next()">Weiter</x-button>
        <x-button type="submit" size="sm" x-show="step === 4">Anwendung anlegen</x-button>
        <x-button tag="a" href="{{ route('admin.applications.index') }}" variant="link" class="ml-auto">Abbrechen</x-button>
    </div>
</form>

<script>
    function applicationWizard(initial) {
        return {
            step: initial.step,
            providerMode: initial.providerMode,
            name: initial.name,
            visibility: initial.visibility,
            stepLabels: ['Anwendung', 'Provider', 'Konfiguration', 'Zusammenfassung'],
            get visibleStep() { return this.step; },
            next() {
                if (this.step === 2 && this.providerMode === 'none') { this.step = 4; return; }
                this.step = Math.min(4, this.step + 1);
            },
            back() {
                if (this.step === 4 && this.providerMode === 'none') { this.step = 2; return; }
                this.step = Math.max(1, this.step - 1);
            },
        };
    }
</script>
@endsection
