@extends('layouts.app')

@php
    $steps = [
        'requirements' => 'Systemprüfung',
        'database' => 'Datenbank',
        'system' => 'System',
        'admin' => 'Administrator',
        'directory' => 'Active Directory',
        'windows-sso' => 'Windows SSO',
        'finish' => 'Abschluss',
    ];

    $showStepper = ! request()->routeIs('install.index', 'install.restore');
    $currentKey = collect($steps)->keys()->first(fn ($key) => request()->routeIs("install.$key")) ?? 'requirements';
    $currentIndex = array_search($currentKey, array_keys($steps), true);
    $currentIndex = $currentIndex === false ? 0 : $currentIndex;
    $currentLabel = $steps[$currentKey] ?? '';
@endphp

@section('content')
<div class="min-h-screen bg-gray-100 py-12">
    <div class="max-w-2xl mx-auto px-4">
        <div class="flex items-center gap-3 mb-2">
            <span class="flex items-center justify-center h-11 w-11 rounded-xl bg-laravel-600 text-white shrink-0">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
            </span>
            <div>
                <h1 class="text-xl font-semibold text-gray-900">{{ config('app.name') }} einrichten</h1>
                @if ($showStepper)
                    <p class="text-sm text-gray-500">Schritt {{ $currentIndex + 1 }} von {{ count($steps) }}: {{ $currentLabel }}</p>
                @else
                    <p class="text-sm text-gray-500">Ersteinrichtung</p>
                @endif
            </div>
        </div>

        @if ($showStepper)
        <ol class="flex items-center gap-1.5 my-6">
            @foreach (array_values($steps) as $i => $label)
                @php
                    $state = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'todo');
                @endphp
                <li class="flex items-center gap-1.5 shrink-0" @if ($i > 0) style="flex:1" @endif>
                    @if ($i > 0)
                        <span class="h-px flex-1 {{ $i <= $currentIndex ? 'bg-laravel-500' : 'bg-gray-300' }}"></span>
                    @endif
                    <span title="{{ $label }}"
                          class="flex items-center justify-center h-7 w-7 rounded-full text-xs font-semibold border
                          @class([
                              'bg-laravel-600 border-laravel-600 text-white' => $state === 'done',
                              'bg-white border-laravel-600 text-laravel-600 ring-2 ring-laravel-100' => $state === 'current',
                              'bg-white border-gray-300 text-gray-400' => $state === 'todo',
                          ])">
                        @if ($state === 'done')
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>

        <p class="text-center text-sm font-medium text-laravel-600 mb-4">{{ $currentLabel }}</p>
        @endif

        <x-flash />

        <div class="bg-white shadow-sm rounded-xl border border-gray-200 p-6 sm:p-8">
            @yield('install-content')
        </div>

        <div class="mt-8 flex justify-center">
            <x-theme-toggle />
        </div>
    </div>
</div>
@endsection
