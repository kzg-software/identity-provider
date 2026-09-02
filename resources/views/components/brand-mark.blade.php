@props(['context' => 'header'])

@php
    // Symbol-Einstellungen kommen aus den Systemeinstellungen (View::share im
    // AppServiceProvider). Fällt eines weg, gilt der Standard.
    $mode = $brandIcon['mode'] ?? 'default';
    $shape = $brandIcon['shape'] ?? 'rounded';

    $shapeClass = match ($shape) {
        'circle' => 'rounded-full',
        'square' => 'rounded-none',
        default => 'rounded-md',
    };

    $isLogin = $context === 'login';
    $boxClass = $isLogin ? 'h-14 w-14' : 'h-8 w-8';
    $glyphClass = $isLogin ? 'h-8 w-8' : 'h-5 w-5';
    $spacing = $isLogin ? 'mb-3' : '';

    $initial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(trim((string) ($systemName ?? '')), 0, 1)) ?: 'A';
@endphp

@if (! empty($systemLogoUrl))
    <img src="{{ $systemLogoUrl }}" alt="{{ $systemName }}"
         class="{{ $isLogin ? 'max-h-14 '.$spacing : 'h-8 max-w-[10rem]' }} object-contain {{ $shape === 'rounded' ? '' : $shapeClass }}">
@elseif ($mode === 'hidden')
    {{-- Symbol ausgeblendet --}}
@elseif ($mode === 'initial')
    <span class="flex items-center justify-center {{ $boxClass }} {{ $shapeClass }} bg-laravel-600 text-white font-semibold {{ $isLogin ? 'text-xl '.$spacing : 'text-sm' }}">
        {{ $initial }}
    </span>
@else
    <span class="flex items-center justify-center {{ $boxClass }} {{ $shapeClass }} bg-laravel-600 text-white {{ $spacing }}">
        <svg class="{{ $glyphClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 3 6v6c0 5.25 3.75 9.75 9 11 5.25-1.25 9-5.75 9-11V6l-9-4Z"/></svg>
    </span>
@endif
