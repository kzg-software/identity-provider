@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Workflows"
    description="Legt fest, was bei der Anmeldung, beim Öffnen einer Anwendung und nach bestimmten Ereignissen passiert. Die Abläufe werden im Editor aus Bausteinen zusammengesetzt.">
</x-page-header>


<div class="space-y-6">
    <x-card title="Standard-Abläufe" description="Gelten für alle Anwendungen, solange eine Anwendung keinen eigenen Ablauf hat.">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($globals as $workflow)
                <a href="{{ route('admin.workflows.edit', $workflow) }}" class="group block rounded-lg border border-gray-200 p-4 transition hover:border-laravel-300 hover:shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ \App\Models\Workflow::phaseLabel($workflow->phase) }}</span>
                        <x-badge color="green">immer aktiv</x-badge>
                    </div>
                    <h3 class="mt-1 font-semibold text-gray-900 group-hover:text-laravel-700">{{ $workflow->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $workflow->phase === 'login' ? 'Läuft nach Passwort, Passkey oder Windows-Anmeldung, bevor man angemeldet ist.' : 'Läuft, wenn ein angemeldetes Konto eine OIDC- oder SAML-Anwendung öffnet.' }}
                    </p>
                    <p class="mt-3 text-xs text-gray-400">
                        {{ count($workflow->graph['nodes'] ?? []) }} Bausteine · Version {{ $workflow->version }} · geändert {{ $workflow->updated_at?->format('d.m.Y H:i') }}
                    </p>
                </a>
            @endforeach
        </div>
    </x-card>

    <x-card title="Abläufe einzelner Anwendungen" description="Ersetzen für diese Anwendung den Standard-Ablauf. Anlegen über die Anwendung, Reiter „Workflow“.">
        @if ($overrides->isEmpty())
            <p class="text-sm text-gray-500">Keine Anwendung hat einen eigenen Ablauf.</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($overrides as $workflow)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('admin.workflows.edit', $workflow) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $workflow->name }}</a>
                            <p class="text-xs text-gray-500">{{ \App\Models\Workflow::phaseLabel($workflow->phase) }} · {{ $workflow->application?->name }}</p>
                        </div>
                        <x-badge :color="$workflow->is_active ? 'green' : 'gray'">{{ $workflow->is_active ? 'aktiv' : 'inaktiv' }}</x-badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card title="Ereignis-Workflows" description="Laufen im Hintergrund nach Ereignissen wie „Anmeldung fehlgeschlagen“ oder „neues Gerät“, z. B. um Administratoren zu benachrichtigen oder einen Webhook aufzurufen.">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.workflows.events.store') }}">
                @csrf
                <x-button type="submit" size="sm"><x-icon name="plus" class="h-4 w-4" />Ereignis-Workflow anlegen</x-button>
            </form>
        </x-slot:actions>
        @if ($events->isEmpty())
            <p class="text-sm text-gray-500">Noch keine Ereignis-Workflows.</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($events as $workflow)
                    @php $trigger = collect($workflow->graph['nodes'] ?? [])->firstWhere('type', 'trigger'); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a href="{{ route('admin.workflows.edit', $workflow) }}" class="font-medium text-laravel-600 hover:text-laravel-700">{{ $workflow->name }}</a>
                            <p class="text-xs text-gray-500">
                                {{ collect($trigger['config']['events'] ?? [])->map(fn ($e) => \App\Workflow\EventTriggers::label($e))->implode(', ') ?: 'kein Auslöser' }}
                            </p>
                        </div>
                        <x-badge :color="$workflow->is_active ? 'green' : 'gray'">{{ $workflow->is_active ? 'aktiv' : 'inaktiv' }}</x-badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
@endsection
