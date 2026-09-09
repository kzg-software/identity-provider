@extends('layouts.admin')

@section('admin-content')
@php
    $schedulerOk = $schedulerHeartbeat && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($schedulerHeartbeat)) < 15;
@endphp

<x-page-header
    title="E-Mail-Warteschlange"
    description="Ausgehende E-Mails (Passwort zurücksetzen, Benachrichtigungen) laufen über eine Warteschlange und werden im Hintergrund zugestellt.">
    <x-slot:actions>
        @if ($pendingTotal > 0)
            <form method="POST" action="{{ route('admin.mail-queue.process') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">
                    <x-icon name="arrow-path" class="h-4 w-4" />Jetzt abarbeiten
                </x-button>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

@unless ($mailConfigured)
    <x-alert type="warning">
        Der E-Mail-Versand ist noch nicht eingerichtet. E-Mails bleiben in der Warteschlange, bis unter
        <a href="{{ route('admin.settings.edit') }}" class="font-medium underline">Systemeinstellungen, E-Mail</a> ein SMTP-Zugang hinterlegt ist.
    </x-alert>
@endunless

@unless ($schedulerOk)
    <x-alert type="warning">
        Der Aufgabenplaner sendet keine Lebenszeichen. Ohne ihn wird die Warteschlange nicht automatisch abgearbeitet.
        Prüfe, ob <code>php artisan schedule:run</code> per Cron läuft, oder arbeite die Warteschlange oben von Hand ab.
    </x-alert>
@endunless

<div class="mb-6 grid gap-4 sm:grid-cols-2">
    <x-card>
        <div class="text-xs text-gray-400">In Warteschlange</div>
        <div class="mt-1 text-2xl font-semibold {{ $pendingTotal > 0 ? 'text-gray-900' : 'text-gray-400' }}">{{ $pendingTotal }}</div>
        <div class="mt-1 text-xs text-gray-500">warten auf Zustellung</div>
    </x-card>
    <x-card>
        <div class="text-xs text-gray-400">Fehlgeschlagen</div>
        <div class="mt-1 text-2xl font-semibold {{ $failedTotal > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $failedTotal }}</div>
        <div class="mt-1 text-xs text-gray-500">nach 3 vergeblichen Versuchen</div>
    </x-card>
</div>

{{-- Wartende E-Mails --}}
<x-card title="Wartende E-Mails" icon="mail" :padding="false" class="mb-6">
    @if ($pending->isNotEmpty())
        <x-slot:actions>
            <x-confirm-form :action="route('admin.mail-queue.cancel-all')"
                            title="Alle abbrechen"
                            message="Alle noch nicht versendeten E-Mails aus der Warteschlange entfernen?"
                            label="Alle abbrechen" variant="secondary" size="sm" icon="trash" />
        </x-slot:actions>
    @endif

    @if ($pending->isEmpty())
        <x-empty-state icon="check-circle" title="Warteschlange ist leer">
            Es warten keine E-Mails auf Zustellung.
        </x-empty-state>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach ($pending as $job)
                <li class="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-900">
                            {{ $job['job'] }}@if ($job['recipient']) <span class="font-normal text-gray-500">an {{ $job['recipient'] }}</span>@endif
                        </div>
                        <div class="mt-0.5 text-xs text-gray-400">
                            in Warteschlange seit {{ $job['queued_at']->diffForHumans() }}
                            @if ($job['attempts'] > 0) &middot; {{ $job['attempts'] }}. Versuch @endif
                        </div>
                    </div>
                    @if ($job['in_progress'])
                        <span class="shrink-0 whitespace-nowrap rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">wird gesendet</span>
                    @else
                        <x-confirm-form :action="route('admin.mail-queue.cancel', $job['id'])"
                                        message="Diese E-Mail abbrechen? Sie wird dann nicht versendet."
                                        label="Abbrechen" variant="secondary" size="sm" icon="trash" />
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-card>

{{-- Fehlgeschlagene E-Mails --}}
<x-card title="Fehlgeschlagene E-Mails" icon="mail" :padding="false">
    @if ($failedTotal > 0)
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.mail-queue.retry-all') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">Alle erneut zustellen</x-button>
            </form>
            <x-confirm-form :action="route('admin.mail-queue.flush')"
                            title="Alle verwerfen"
                            message="Alle fehlgeschlagenen E-Mails endgültig aus der Liste entfernen?"
                            label="Alle verwerfen" variant="secondary" size="sm" icon="trash" />
        </x-slot:actions>
    @endif

    @if ($failed->isEmpty())
        <x-empty-state icon="check-circle" title="Nichts fehlgeschlagen">
            Alle E-Mails wurden zugestellt oder sind noch in der Warteschlange.
        </x-empty-state>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach ($failed as $job)
                <li class="px-4 py-3.5 sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900">
                                {{ $job['job'] }}@if ($job['recipient']) <span class="font-normal text-gray-500">an {{ $job['recipient'] }}</span>@endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-400" title="{{ $job['failed_at']->format('d.m.Y H:i:s') }}">
                                fehlgeschlagen {{ $job['failed_at']->diffForHumans() }}
                            </div>
                            <p class="mt-1 break-words text-xs text-red-600">{{ $job['error'] }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <form method="POST" action="{{ route('admin.mail-queue.retry', $job['uuid']) }}">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm">Erneut</x-button>
                            </form>
                            <x-confirm-form :action="route('admin.mail-queue.forget', $job['uuid'])"
                                            message="Diesen Eintrag verwerfen?"
                                            label="Verwerfen" variant="secondary" size="sm" icon="trash" />
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-card>
@endsection
