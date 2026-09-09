@props(['application'])

@php
    $inMaintenance = $application->isUnderMaintenanceFor(auth()->user());
    $logoUrl = $application->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($application->logo_path)
        : null;
    $href = (! $inMaintenance && $application->launch_url) ? $application->launch_url : null;
    $base = 'group flex h-full flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm';
@endphp

@if ($href)
    <a href="{{ $href }}" target="_blank" rel="noopener" class="{{ $base }} transition hover:border-laravel-300 hover:shadow-md">
@else
    <div class="{{ $base }} {{ $inMaintenance ? 'opacity-75' : '' }}">
@endif

    <div class="flex items-start gap-3">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-contain bg-gray-50 ring-1 ring-gray-200">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-laravel-50 text-laravel-600">
                <x-icon name="building" class="h-5 w-5" />
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <h3 class="truncate text-sm font-semibold text-gray-900">{{ $application->name }}</h3>
            @if ($inMaintenance)
                <x-badge color="amber" class="mt-1">In Wartung</x-badge>
            @endif
        </div>
        @if ($href)
            <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-laravel-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6.194 12.753a.75.75 0 001.06.053L16.5 4.44v2.81a.75.75 0 001.5 0v-4.5a.75.75 0 00-.75-.75h-4.5a.75.75 0 000 1.5h2.553l-9.056 8.194a.75.75 0 00-.053 1.06z" clip-rule="evenodd" /><path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 00-.75.75v9c0 .414.336.75.75.75h9a.75.75 0 00.75-.75v-4a.75.75 0 011.5 0v4A2.25 2.25 0 0113.25 17h-9A2.25 2.25 0 012 14.75v-9A2.25 2.25 0 014.25 3.5h4a.75.75 0 010 1.5h-4z" clip-rule="evenodd" /></svg>
        @endif
    </div>

    @if ($application->description)
        <p class="mt-2 line-clamp-2 text-sm text-gray-500">{{ $application->description }}</p>
    @endif

    <div class="flex-grow"></div>

    @if ($inMaintenance)
        <p class="mt-3 text-xs text-amber-700">{{ \App\Support\MaintenanceGate::applicationMessage($application) }}</p>
    @elseif (! $application->launch_url)
        <x-badge class="mt-3 self-start">Keine Start-URL hinterlegt</x-badge>
    @endif

@if ($href)
    </a>
@else
    </div>
@endif
