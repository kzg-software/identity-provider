@extends('layouts.admin')

@section('admin-content')
<div class="max-w-3xl space-y-6">
    <x-page-header title="Verbundene Anwendungen" :back="route('profile.index')" back-label="Mein Account"
                   description="Anwendungen, denen du über dieses Konto Zugriff auf deine Daten erlaubt hast." />

    @if ($apps->isEmpty())
        <x-empty-state icon="grid" title="Keine verbundenen Anwendungen">
            Sobald du dich bei einer Anwendung über dieses Konto anmeldest und den Zugriff bestätigst, erscheint sie hier.
        </x-empty-state>
    @else
        <x-card title="Zugriffe" icon="grid" :padding="false"
                description="Das Entziehen beendet den Zugriff sofort. Bei der nächsten Anmeldung wirst du erneut um Bestätigung gebeten.">
            <x-slot:actions>
                <x-badge>{{ $apps->count() }}</x-badge>
            </x-slot:actions>

            <ul class="divide-y divide-gray-100">
                @foreach ($apps as $app)
                    <li class="flex items-center gap-4 px-4 py-3.5 sm:px-6">
                        @if ($app['logo_url'])
                            <img src="{{ $app['logo_url'] }}" alt="" class="h-10 w-10 shrink-0 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200">
                        @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-laravel-50 text-laravel-600">
                                <x-icon name="grid" class="h-5 w-5" />
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-gray-900">{{ $app['name'] }}</div>
                            @if ($app['scopes']->isNotEmpty())
                                <div class="mt-0.5 truncate text-xs text-gray-500">Zugriff auf: {{ $app['scopes']->join(', ') }}</div>
                            @endif
                            <div class="mt-0.5 text-xs text-gray-400">
                                {{ $app['granted_at'] ? 'Bestätigt am '.$app['granted_at']->isoFormat('LL') : 'Aktive Anmeldung' }}
                            </div>
                        </div>

                        <x-confirm-form :action="route('profile.apps.destroy', $app['client_id'])"
                                        title="Zugriff entziehen"
                                        :message="'„'.$app['name'].'“ verliert den Zugriff auf dein Konto und alle bestehenden Sitzungen dieser Anwendung werden ungültig.'"
                                        label="Entziehen" variant="secondary" size="sm" icon="trash" />
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</div>
@endsection
