@props(['application'])

@php $inMaintenance = $application->isUnderMaintenanceFor(auth()->user()); @endphp

<x-card class="flex flex-col {{ $inMaintenance ? 'opacity-75' : '' }}">
    <div class="flex items-start justify-between gap-2">
        <h2 class="text-sm font-semibold text-gray-900">{{ $application->name }}</h2>
        @if ($inMaintenance)
            <x-badge color="amber" class="shrink-0">In Wartung</x-badge>
        @endif
    </div>
    @if ($application->description)
        <p class="text-sm text-gray-500 mt-1 flex-grow">{{ $application->description }}</p>
    @else
        <div class="flex-grow"></div>
    @endif
    @if ($inMaintenance)
        <p class="mt-3 text-xs text-amber-700">{{ \App\Support\MaintenanceGate::applicationMessage($application) }}</p>
    @elseif ($application->launch_url)
        <x-button tag="a" href="{{ $application->launch_url }}" target="_blank" rel="noopener" size="sm" class="mt-3 self-start">
            Öffnen
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 00-.75.75v9c0 .414.336.75.75.75h9a.75.75 0 00.75-.75v-4a.75.75 0 011.5 0v4A2.25 2.25 0 0113.25 17h-9A2.25 2.25 0 012 14.75v-9A2.25 2.25 0 014.25 3.5h4a.75.75 0 010 1.5h-4z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 001.06.053L16.5 4.44v2.81a.75.75 0 001.5 0v-4.5a.75.75 0 00-.75-.75h-4.5a.75.75 0 000 1.5h2.553l-9.056 8.194a.75.75 0 00-.053 1.06z" clip-rule="evenodd" /></svg>
        </x-button>
    @else
        <x-badge class="mt-3 self-start">Keine Start-URL hinterlegt</x-badge>
    @endif
</x-card>
