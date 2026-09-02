@extends('layouts.app')

@section('content')
@php $hasLoginBg = ! empty($loginBackgroundUrl); @endphp
<div class="relative min-h-screen flex flex-col {{ $hasLoginBg ? 'bg-gray-900' : 'bg-gray-100' }}"
     @if ($hasLoginBg) style="background-image:url('{{ $loginBackgroundUrl }}');background-size:cover;background-position:center;background-repeat:no-repeat;" @endif>
    @if ($hasLoginBg)
        {{-- Leichter Schleier für Kontrast, unabhängig vom gewählten Bild --}}
        <div class="absolute inset-0 bg-black/40"></div>
    @endif

    <div class="relative z-10 flex-1 w-full flex flex-col items-center justify-center px-4 py-10">
        <div class="mb-6 flex flex-col items-center">
            <x-brand-mark context="login" />
            @if (! empty($loginTitle))
                <span class="text-xl font-semibold {{ $hasLoginBg ? 'text-white drop-shadow' : 'text-gray-800' }}">{{ $loginTitle }}</span>
            @endif
        </div>

        <div class="w-full sm:max-w-md px-6 py-8 bg-white shadow-sm sm:rounded-lg border border-gray-200 {{ $hasLoginBg ? 'shadow-xl' : '' }}">
            <x-flash />

            @yield('auth-content')
        </div>

        <div class="mt-6">
            <x-theme-toggle />
        </div>
    </div>

    @php
        $fpVersion = \App\Support\Version::current();
        $fpRepoUrl = \App\Services\UpdateChecker::repositoryUrl();
        $fpVersionUrl = \App\Services\UpdateChecker::releaseUrl($fpVersion);
        $fpMuted = $hasLoginBg ? 'text-white/50 hover:text-white/80' : 'text-gray-400 hover:text-gray-500';
    @endphp
    <footer class="relative z-10 shrink-0 w-full px-4 pb-5 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-xs {{ $fpMuted }}">
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
@endsection
