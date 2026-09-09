@extends('layouts.admin')

@section('admin-content')
@php
    $prefs = $user->notification_email_prefs ?? [];
    $noEmail = blank($user->email);
    $hasAdjustable = $categories->contains(fn ($c) => ! $c['locked']);
@endphp

<div class="max-w-3xl space-y-6">
    <x-page-header title="Benachrichtigungen" :back="route('profile.index')" back-label="Mein Account"
                   description="Lege fest, welche Hinweise du zusätzlich per E-Mail bekommst. An der Glocke erscheinen sie unabhängig davon." />

    @if ($noEmail)
        <x-alert type="warning">Für dein Konto ist keine E-Mail-Adresse hinterlegt. Es können keine E-Mails zugestellt werden.</x-alert>
    @elseif (! $mailConfigured)
        <x-alert type="warning">Der E-Mail-Versand ist im System noch nicht eingerichtet. Bis dahin kommen Hinweise nur an die Glocke.</x-alert>
    @endif

    <form method="POST" action="{{ route('profile.notifications.update') }}">
        @csrf

        <x-card title="E-Mail-Benachrichtigungen" icon="mail" :padding="false">
            <ul class="divide-y divide-gray-100">
                @foreach ($categories as $key => $category)
                    <li class="flex items-start justify-between gap-4 px-4 py-3.5 sm:px-6">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900">{{ $category['label'] }}</div>
                            <div class="mt-0.5 text-xs text-gray-500">{{ $category['description'] }}</div>
                        </div>

                        @if ($category['locked'])
                            <span class="mt-0.5 shrink-0 whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                  title="Sicherheitsrelevant, immer aktiv">Immer aktiv</span>
                        @else
                            @php($on = ($prefs[$key] ?? true) && ! $noEmail)
                            <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                                <input type="checkbox" name="categories[{{ $key }}]" value="1" class="peer sr-only"
                                       @checked($on) @disabled($noEmail)>
                                <span class="h-6 w-11 rounded-full bg-gray-200 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all after:content-[''] peer-checked:bg-laravel-600 peer-checked:after:translate-x-5 peer-disabled:opacity-50"></span>
                            </label>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>

        @if ($hasAdjustable && ! $noEmail)
            <div class="mt-4">
                <x-button type="submit">Speichern</x-button>
            </div>
        @endif
    </form>
</div>
@endsection
