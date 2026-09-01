@extends('layouts.auth-card')

@section('auth-content')
<h1 class="text-lg font-semibold text-gray-900 mb-2">Zugriff anfragen</h1>
<p class="text-sm text-gray-600 mb-4"><strong>{{ $application->name }}</strong> möchte auf folgende Daten zugreifen:</p>

<ul class="divide-y divide-gray-100 mb-4">
    @foreach ($scopes as $scope)
        <li class="py-2">
            <div class="text-sm font-semibold text-gray-900">{{ $scope->label }}</div>
            <div class="text-sm text-gray-500">{{ $scope->description }}</div>
        </li>
    @endforeach
</ul>

<form method="POST" action="{{ route('oauth.authorize.decision') }}">
    @csrf
    <label class="flex items-center gap-2 text-sm text-gray-600 mb-4">
        <x-checkbox name="remember" value="1" />
        Entscheidung speichern (nicht erneut fragen)
    </label>
    <div class="flex gap-3">
        <x-button type="submit" name="decision" value="allow" class="flex-1">Zulassen</x-button>
        <x-button type="submit" name="decision" value="deny" variant="secondary" class="flex-1">Ablehnen</x-button>
    </div>
</form>
@endsection
