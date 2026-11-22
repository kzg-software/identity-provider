@extends('layouts.app')

@section('body-class', 'idp-login-page')

@push('custom-css')
    <x-custom-css area="login" />
@endpush

@section('content')
@php
    try {
        $loginBackground = \App\Support\LoginBackgrounds::forDisplay();
    } catch (\Throwable) {
        $loginBackground = ['urls' => [], 'mode' => 'fixed', 'interval' => 12, 'overlay' => 40];
    }
    $hasLoginBg = ! empty($loginBackground['urls']);
    $isSplit = ($loginLayout ?? 'centered') === 'split';

    $fpVersion = \App\Support\Version::current();
    $fpRepoUrl = \App\Services\UpdateChecker::repositoryUrl();
    $fpVersionUrl = \App\Services\UpdateChecker::releaseUrl($fpVersion);
@endphp

@if ($isSplit)
    {{-- Geteiltes Layout: links Bild/Marke, rechts das Anmeldeformular. --}}
    <div class="idp-login idp-login--split min-h-screen flex flex-col lg:flex-row bg-white">
        <div class="idp-login-aside relative hidden lg:flex lg:w-[44%] xl:w-1/2 shrink-0 flex-col justify-between overflow-hidden bg-gray-900 text-white">
            <x-login-background :background="$loginBackground" shade="gradient" />

            <div class="idp-login-brand relative z-10 flex items-center gap-2.5 p-10">
                <x-brand-mark context="login" />
                @if (! empty($loginTitle))
                    <span class="text-lg font-semibold text-white drop-shadow">{{ $loginTitle }}</span>
                @endif
            </div>

            @unless ($hasLoginBg)
                {{-- Ohne Hintergrundbild bleibt die Fläche sonst leer: dezentes, großes Symbol als Füllung. --}}
                <div class="absolute inset-0 flex items-center justify-center opacity-[0.06]">
                    <svg class="h-72 w-72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
                </div>
            @endunless

            <div class="relative z-10 p-10 text-sm text-white/40">{{ $systemName }}</div>
        </div>

        <div class="flex flex-1 flex-col min-w-0">
            <div class="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-8">
                {{-- Auf kleinen Bildschirmen ist die linke Spalte ausgeblendet, daher die Marke hier zeigen. --}}
                <div class="mb-6 flex flex-col items-center lg:hidden">
                    <x-brand-mark context="login" />
                    @if (! empty($loginTitle))
                        <span class="text-xl font-semibold text-gray-800">{{ $loginTitle }}</span>
                    @endif
                </div>

                <div class="idp-login-card w-full sm:max-w-sm">
                    <x-flash />

                    @yield('auth-content')
                </div>

                <div class="mt-6">
                    <x-theme-toggle />
                </div>
            </div>

            <footer class="idp-login-footer shrink-0 w-full px-4 pb-5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs text-gray-400">
                <span>{{ $systemName }}</span>
                <span aria-hidden="true">&middot;</span>
                @if (\App\Support\Version::isRelease())
                    <a href="{{ $fpVersionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:text-gray-500 hover:underline">{{ $fpVersion }}</a>
                @else
                    <span>{{ $fpVersion }}</span>
                @endif
                <span aria-hidden="true">&middot;</span>
                <a href="{{ $fpRepoUrl }}" target="_blank" rel="noopener noreferrer" class="hover:text-gray-500 hover:underline">Quellcode</a>
            </footer>
        </div>
    </div>
@else
    {{-- Zentriertes Layout (Standard): Karte mittig, optional mit Hintergrundbild. --}}
    <div class="idp-login idp-login--centered relative min-h-screen flex flex-col {{ $hasLoginBg ? 'bg-gray-900' : 'bg-gray-100' }}">
        {{-- Hintergrundbild(er) mit einstellbarer Abdunklung für Kontrast --}}
        <x-login-background :background="$loginBackground" />

        <div class="relative z-10 flex-1 w-full flex flex-col items-center justify-center px-4 py-10">
            <div class="idp-login-brand mb-6 flex flex-col items-center">
                <x-brand-mark context="login" />
                @if (! empty($loginTitle))
                    <span class="text-xl font-semibold {{ $hasLoginBg ? 'text-white drop-shadow' : 'text-gray-800' }}">{{ $loginTitle }}</span>
                @endif
            </div>

            <div class="idp-login-card w-full sm:max-w-md px-6 py-8 bg-white shadow-sm sm:rounded-lg border border-gray-200 {{ $hasLoginBg ? 'shadow-xl' : '' }}">
                <x-flash />

                @yield('auth-content')
            </div>

            <div class="mt-6">
                <x-theme-toggle />
            </div>
        </div>

        @php $fpMuted = $hasLoginBg ? 'text-white/50 hover:text-white/80' : 'text-gray-400 hover:text-gray-500'; @endphp
        <footer class="idp-login-footer relative z-10 shrink-0 w-full px-4 pb-5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs {{ $fpMuted }}">
            <span>{{ $systemName }}</span>
            <span aria-hidden="true">&middot;</span>
            @if (\App\Support\Version::isRelease())
                <a href="{{ $fpVersionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $fpVersion }}</a>
            @else
                <span>{{ $fpVersion }}</span>
            @endif
            <span aria-hidden="true">&middot;</span>
            <a href="{{ $fpRepoUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">Quellcode</a>
        </footer>
    </div>
@endif
@endsection
