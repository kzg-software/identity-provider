@extends('layouts.admin')

@section('admin-content')
<h1 class="text-2xl font-semibold text-gray-900 mb-1">Willkommen, {{ auth()->user()->display_name ?: auth()->user()->name }}</h1>
<p class="text-gray-500 mb-6">Diese Anwendungen stehen dir zur Verfügung.</p>

@if ($applications->isEmpty())
    <x-alert type="info">
        Dir wurde bisher keine Anwendung freigegeben. Wende dich an deine Administration, falls du Zugriff auf eine bestimmte Anwendung benötigst.
    </x-alert>
@else
    <div class="space-y-8">
        {{-- Bereiche entstehen dynamisch aus dem "Bereich"-Feld der Anwendung (Admin) --}}
        {{-- und werden nur angezeigt, wenn der Benutzer mindestens eine Anwendung darin sehen darf. --}}
        @foreach ($categorizedApplications as $category => $apps)
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">{{ $category }}</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($apps as $application)
                        <x-application-tile :application="$application" />
                    @endforeach
                </div>
            </div>
        @endforeach

        @if ($uncategorizedApplications->isNotEmpty())
            <div>
                @if ($categorizedApplications->isNotEmpty())
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">Weitere Anwendungen</h2>
                @endif
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($uncategorizedApplications as $application)
                        <x-application-tile :application="$application" />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
@endsection
