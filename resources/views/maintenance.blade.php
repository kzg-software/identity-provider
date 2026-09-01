@extends('layouts.auth-card')

@section('auth-content')
<div class="text-center">
    <div class="flex items-center justify-center h-12 w-12 mx-auto rounded-full bg-amber-100 text-amber-600 mb-4">
        <x-icon name="heart-pulse" class="h-6 w-6" />
    </div>
    <h1 class="text-lg font-semibold text-gray-900 mb-2">Wartungsmodus</h1>
    <p class="text-sm text-gray-600 whitespace-pre-line">{{ $message }}</p>
    <a href="{{ route('login') }}" class="inline-block mt-6 text-sm font-medium text-laravel-600 hover:text-laravel-700">Zur Anmeldung</a>
</div>
@endsection
