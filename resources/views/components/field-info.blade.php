@props(['required' => 'optional', 'example' => null])

@php
    // required: true / "required" -> Pflichtfeld
    //           "connection"      -> nur für die Verbindung nötig
    //           sonst             -> optional
    $key = $required === true ? 'required' : (string) $required;

    [$badgeText, $badgeClass] = match ($key) {
        'required', '1', 'true' => ['Pflichtfeld', 'bg-amber-100 text-amber-700'],
        'connection' => ['Für die Verbindung nötig', 'bg-blue-100 text-blue-700'],
        default => ['Optional', 'bg-gray-100 text-gray-600'],
    };
@endphp

<span class="relative inline-flex" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @click="open = ! open"
            class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-300 text-[10px] font-semibold leading-none text-gray-500 transition hover:border-laravel-400 hover:text-laravel-600"
            aria-label="Erklärung zu diesem Feld">i</button>

    <span x-show="open" x-transition x-cloak
          class="absolute left-0 top-6 z-20 w-64 rounded-md border border-gray-200 bg-white p-3 text-left text-xs font-normal leading-relaxed text-gray-600 shadow-lg">
        <span class="mb-1.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-medium {{ $badgeClass }}">{{ $badgeText }}</span>
        <span class="block">{{ $slot }}</span>
        @if ($example)
            <span class="mt-1.5 block text-gray-500">Beispiel: <code class="rounded bg-gray-100 px-1 text-gray-700">{{ $example }}</code></span>
        @endif
    </span>
</span>
