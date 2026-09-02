@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Willkommen</h2>
<p class="text-sm text-gray-500 mb-6">Wie möchten Sie starten?</p>

<div class="grid gap-4 sm:grid-cols-2">
    <a href="{{ route('install.requirements') }}"
       class="group flex flex-col rounded-xl border border-gray-200 p-5 hover:border-laravel-500 hover:bg-laravel-50/40 transition">
        <span class="flex items-center justify-center h-10 w-10 rounded-lg bg-laravel-600 text-white mb-3">
            <x-icon name="sparkles" class="h-5 w-5" />
        </span>
        <span class="font-semibold text-gray-900">Neu einrichten</span>
        <span class="mt-1 text-sm text-gray-500">Ein frisches System in wenigen Schritten aufsetzen: Datenbank, Systemname, Administrator und Active Directory.</span>
        <span class="mt-3 text-sm font-medium text-laravel-600 group-hover:underline">Einrichtung starten</span>
    </a>

    <a href="{{ route('install.restore') }}"
       class="group flex flex-col rounded-xl border border-gray-200 p-5 hover:border-laravel-500 hover:bg-laravel-50/40 transition">
        <span class="flex items-center justify-center h-10 w-10 rounded-lg bg-gray-700 text-white mb-3">
            <x-icon name="arrow-path" class="h-5 w-5" />
        </span>
        <span class="font-semibold text-gray-900">Aus Sicherung wiederherstellen</span>
        <span class="mt-1 text-sm text-gray-500">Eine zuvor erstellte Sicherungsdatei einspielen. Das System ist danach im selben Zustand wie zum Zeitpunkt der Sicherung.</span>
        <span class="mt-3 text-sm font-medium text-laravel-600 group-hover:underline">Sicherung einspielen</span>
    </a>
</div>
@endsection
