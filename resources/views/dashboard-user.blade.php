@extends('layouts.admin')

@section('admin-content')
@php
    $hour = (int) now()->format('G');
    $greeting = match (true) {
        $hour < 11 => 'Guten Morgen',
        $hour < 18 => 'Guten Tag',
        default => 'Guten Abend',
    };
@endphp

<div class="mb-8">
    <h1 class="text-2xl font-semibold text-gray-900">{{ $greeting }}, {{ auth()->user()->display_name ?: auth()->user()->name }}</h1>
    <p class="mt-1 text-gray-500">
        @if ($applications->isEmpty())
            Hier erscheinen die Anwendungen, die dir freigegeben wurden.
        @else
            Diese Anwendungen stehen dir zur Verfügung.
        @endif
    </p>
</div>

@if ($applications->isEmpty())
    <x-empty-state icon="grid" title="Noch keine Anwendung freigegeben">
        Wende dich an deine Administration, falls du Zugriff auf eine bestimmte Anwendung benötigst.
    </x-empty-state>
@else
    <div class="space-y-10">
        {{-- Bereiche entstehen dynamisch aus dem "Bereich"-Feld der Anwendung (Admin) --}}
        @foreach ($categorizedApplications as $category => $apps)
            <section>
                <h2 class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    {{ $category }}
                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500">{{ $apps->count() }}</span>
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($apps as $application)
                        <x-application-tile :application="$application" />
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($uncategorizedApplications->isNotEmpty())
            <section>
                @if ($categorizedApplications->isNotEmpty())
                    <h2 class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Weitere Anwendungen
                        <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500">{{ $uncategorizedApplications->count() }}</span>
                    </h2>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($uncategorizedApplications as $application)
                        <x-application-tile :application="$application" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endif
@endsection
