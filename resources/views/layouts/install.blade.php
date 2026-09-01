@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-gray-100 py-10">
    <div class="max-w-2xl mx-auto px-4">
        <div class="flex items-center gap-3 mb-6">
            <span class="flex items-center justify-center h-10 w-10 rounded-full bg-laravel-600 text-white shrink-0">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
            </span>
            <h1 class="text-xl font-semibold text-gray-900">Installation — {{ config('app.name') }}</h1>
        </div>

        <ol class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500 mb-6">
            @foreach (['requirements' => 'Systemprüfung', 'database' => 'Datenbank', 'system' => 'System', 'admin' => 'Administrator', 'directory' => 'Active Directory', 'windows-sso' => 'Windows SSO', 'finish' => 'Abschluss'] as $step => $label)
                <li class="{{ request()->routeIs("install.$step") ? 'font-semibold text-laravel-600' : '' }}">{{ $label }}</li>
            @endforeach
        </ol>

        <x-flash />

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
            @yield('install-content')
        </div>

        <div class="mt-6 flex justify-center">
            <x-theme-toggle />
        </div>
    </div>
</div>
@endsection
