@extends('layouts.auth-card')

@section('auth-content')
<x-login-target :application="$workflowPhase === 'authorize' ? $context->application() : null" hint="Bitte stimme den Bedingungen zu, um fortzufahren." />

<h1 class="text-lg font-semibold text-gray-900 mb-3">{{ $config['title'] }}</h1>
<div class="max-h-72 overflow-y-auto rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 whitespace-pre-line mb-4">{{ $config['text'] }}</div>

@error('workflow')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror

<form method="POST" action="{{ route('workflow.submit') }}" x-data="{ loading: false }" @submit="loading = true">
    @csrf
    <label class="flex items-start gap-2 text-sm text-gray-700 mb-4">
        <x-checkbox name="accept" value="1" class="mt-0.5" />
        Ich habe die Bedingungen gelesen und akzeptiere sie.
    </label>
    <div class="flex gap-3">
        <x-button type="submit" name="decision" value="accept" class="flex-1" x-bind:disabled="loading">Akzeptieren</x-button>
        <x-button type="submit" name="decision" value="decline" variant="secondary" class="flex-1" x-bind:disabled="loading">Ablehnen</x-button>
    </div>
</form>
@endsection
