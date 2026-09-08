@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Provider anlegen"
    :back="route('admin.providers.index')" back-label="Alle Provider"
    description="Die technische SSO-Konfiguration. Nach dem Speichern lässt sie sich einer oder mehreren Anwendungen zuordnen." />

@if (! $type)
    <x-card title="Welcher Provider-Typ?">
        <div class="grid gap-3 sm:grid-cols-2">
            <a href="{{ route('admin.providers.create', ['type' => 'oidc'] + ($application ? ['application' => $application->id] : [])) }}"
               class="rounded-lg border border-gray-200 p-5 transition hover:border-laravel-500 hover:bg-laravel-50">
                <span class="block text-sm font-semibold text-gray-900">OAuth 2.0 / OpenID Connect</span>
                <span class="mt-1 block text-xs text-gray-500">Moderne Web-, SPA- und Mobile-Apps. Tokens, Scopes, PKCE.</span>
            </a>
            <a href="{{ route('admin.providers.create', ['type' => 'saml'] + ($application ? ['application' => $application->id] : [])) }}"
               class="rounded-lg border border-gray-200 p-5 transition hover:border-laravel-500 hover:bg-laravel-50">
                <span class="block text-sm font-semibold text-gray-900">SAML 2.0</span>
                <span class="mt-1 block text-xs text-gray-500">Klassische Enterprise-Anwendungen. Entity ID, ACS, Assertions.</span>
            </a>
        </div>
    </x-card>
@else
    <form method="POST" action="{{ route('admin.providers.store') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        @if ($application)<input type="hidden" name="application" value="{{ $application->id }}">@endif

        @if ($application)
            <x-alert type="info">Wird nach dem Anlegen automatisch mit der Anwendung <strong>{{ $application->name }}</strong> verknüpft.</x-alert>
        @endif

        <x-card :title="$type === 'oidc' ? 'OAuth 2.0 / OpenID Connect' : 'SAML 2.0'">
            <div class="space-y-5">
                <div>
                    <x-input-label value="Name (für die Verwaltung)" />
                    <x-input type="text" name="name" value="{{ old('name', $application?->name) }}" required autofocus />
                </div>

                @if ($type === 'oidc')
                    @include('admin.providers._oidc-fields')
                @else
                    @include('admin.providers._saml-fields')
                @endif
            </div>
        </x-card>

        <div class="flex gap-3">
            <x-button type="submit">Provider anlegen</x-button>
            <x-button tag="a" href="{{ route('admin.providers.index') }}" variant="link">Abbrechen</x-button>
        </div>
    </form>
@endif
@endsection
