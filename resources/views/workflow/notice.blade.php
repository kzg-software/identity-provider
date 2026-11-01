@extends('layouts.auth-card')

@section('auth-content')
<x-login-target :application="$workflowPhase === 'authorize' ? $context->application() : null" hint="Bitte lies den Hinweis, um fortzufahren." />

<h1 class="text-lg font-semibold text-gray-900 mb-3">{{ $config['title'] }}</h1>
<div class="text-sm text-gray-600 whitespace-pre-line mb-5">{{ $config['text'] }}</div>

<form method="POST" action="{{ route('workflow.submit') }}" x-data="{ loading: false }" @submit="loading = true">
    @csrf
    <x-button type="submit" class="w-full" x-bind:disabled="loading">{{ $config['button'] ?: 'Weiter' }}</x-button>
</form>
@endsection
