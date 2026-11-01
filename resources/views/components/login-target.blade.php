@props(['hint' => 'Melde dich an, um fortzufahren.'])

@php
    $targetApplication = \App\Auth\LoginTarget::application(request());
    $targetLogoUrl = $targetApplication?->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($targetApplication->logo_path)
        : null;
@endphp

@if ($targetApplication)
    <div {{ $attributes->merge(['class' => 'mb-5 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3']) }} data-login-target>
        @if ($targetLogoUrl)
            <img src="{{ $targetLogoUrl }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-contain bg-white ring-1 ring-gray-200">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-laravel-50 text-laravel-600">
                <x-icon name="building" class="h-5 w-5" />
            </span>
        @endif
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900">Weiter zu {{ $targetApplication->name }}</p>
            <p class="text-xs text-gray-500">{{ $hint }}</p>
        </div>
    </div>
@endif
