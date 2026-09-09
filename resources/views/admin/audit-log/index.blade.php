@extends('layouts.admin')

@section('admin-content')
@php
    use App\Support\AuditEventLabels;
    $hasFilters = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<x-page-header
    title="Audit-Log"
    description="Wer hat wann was gemacht: Anmeldungen, Änderungen an Anwendungen und Benutzern, Zustimmungen. Nur lesbar.">
    <x-slot:actions>
        <x-button tag="a" href="{{ route('admin.audit-log.export', array_merge($filters, ['format' => 'csv'])) }}" variant="secondary" size="sm">
            <x-icon name="download" class="h-4 w-4" />CSV
        </x-button>
        <x-button tag="a" href="{{ route('admin.audit-log.export', array_merge($filters, ['format' => 'json'])) }}" variant="secondary" size="sm">
            <x-icon name="download" class="h-4 w-4" />JSON
        </x-button>
    </x-slot:actions>
</x-page-header>

<div x-data="{ filters: {{ $hasFilters ? 'true' : 'false' }} }" class="mb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <button type="button" @click="filters = ! filters"
                class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <x-icon name="signpost" class="h-4 w-4" />
            Filter
            @if ($hasFilters)
                <span class="ml-1 rounded-full bg-laravel-100 px-1.5 text-xs text-laravel-700">{{ collect($filters)->filter(fn ($v) => filled($v))->count() }}</span>
            @endif
            <svg class="h-4 w-4 text-gray-400 transition" :class="filters && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>
        <span class="text-sm text-gray-400">{{ $logs->total() }} {{ $logs->total() === 1 ? 'Eintrag' : 'Einträge' }}</span>
    </div>

    <div x-show="filters" x-cloak class="mt-3">
        <x-card>
            <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-input-label value="Benutzer" />
                    <x-select name="user_id" class="w-full">
                        <option value="">Alle</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(($filters['user_id'] ?? null) == $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label value="Ereignis" />
                    <x-input type="text" name="event" list="events" value="{{ $filters['event'] ?? '' }}" placeholder="z. B. login.success" class="w-full" />
                    <datalist id="events">
                        @foreach ($events as $event)
                            <option value="{{ $event }}">
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <x-input-label value="Anwendung" />
                    <x-select name="application_id" class="w-full">
                        <option value="">Alle</option>
                        @foreach ($applications as $application)
                            <option value="{{ $application->id }}" @selected(($filters['application_id'] ?? null) == $application->id)>{{ $application->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label value="Von" />
                    <x-input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full" />
                </div>
                <div>
                    <x-input-label value="Bis" />
                    <x-input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full" />
                </div>
                <div class="flex items-end gap-2">
                    <x-button type="submit" size="sm">Filtern</x-button>
                    @if ($hasFilters)
                        <x-button tag="a" href="{{ route('admin.audit-log.index') }}" variant="secondary" size="sm">Zurücksetzen</x-button>
                    @endif
                </div>
            </form>
        </x-card>
    </div>
</div>

<x-table :heads="['Zeitpunkt', 'Benutzer', 'Ereignis', 'Anwendung', 'IP', '']">
    @forelse ($logs as $log)
        @php($hasDetail = filled($log->metadata) || filled($log->user_agent))
        <tbody x-data="{ open: false }" class="border-b border-gray-100 last:border-0">
            <tr class="{{ $hasDetail ? 'cursor-pointer' : '' }} hover:bg-gray-50" @if ($hasDetail) @click="open = ! open" @endif>
                <td class="whitespace-nowrap px-4 py-2.5 align-top text-gray-500">{{ $log->created_at?->format('d.m.Y H:i') }}</td>
                <td class="px-4 py-2.5 align-top text-gray-700">{{ $log->user?->name ?? ($log->metadata['username'] ?? '–') }}</td>
                <td class="px-4 py-2.5 align-top">
                    <div class="font-medium text-gray-800">{{ AuditEventLabels::label($log->event) }}</div>
                    <code class="text-[11px] text-gray-400">{{ $log->event }}</code>
                </td>
                <td class="px-4 py-2.5 align-top text-gray-600">{{ $log->application?->name ?? '–' }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 align-top text-gray-500">{{ $log->ip_address ?: '–' }}</td>
                <td class="w-0 px-4 py-2.5 align-top text-right">
                    @if ($hasDetail)
                        <svg class="inline h-4 w-4 text-gray-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                        </svg>
                    @endif
                </td>
            </tr>
            @if ($hasDetail)
                <tr x-show="open" x-cloak>
                    <td colspan="6" class="bg-gray-50 px-4 py-3 sm:px-6">
                        <dl class="grid gap-x-8 gap-y-1.5 sm:grid-cols-2">
                            <div class="flex gap-2 text-xs">
                                <dt class="w-28 shrink-0 font-medium text-gray-500">Genauer Zeitpunkt</dt>
                                <dd class="text-gray-700">{{ $log->created_at?->format('d.m.Y H:i:s') }}</dd>
                            </div>
                            @foreach (($log->metadata ?? []) as $key => $value)
                                <div class="flex gap-2 text-xs">
                                    <dt class="w-28 shrink-0 font-medium text-gray-500">{{ $key }}</dt>
                                    <dd class="min-w-0 break-words text-gray-700">{{ is_scalar($value) || $value === null ? ($value ?? '–') : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                                </div>
                            @endforeach
                            @if ($log->user_agent)
                                <div class="flex gap-2 text-xs sm:col-span-2">
                                    <dt class="w-28 shrink-0 font-medium text-gray-500">User-Agent</dt>
                                    <dd class="min-w-0 break-words text-gray-700">{{ $log->user_agent }}</dd>
                                </div>
                            @endif
                        </dl>
                    </td>
                </tr>
            @endif
        </tbody>
    @empty
        <tbody>
            <x-empty-state cell :colspan="6" icon="journal" title="Keine Einträge">
                Für diese Filter gibt es nichts. Setze die Filter zurück oder warte auf neue Ereignisse.
            </x-empty-state>
        </tbody>
    @endforelse
</x-table>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection
