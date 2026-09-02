@props([
    'title',
    'description' => null,
    'back' => null,
    'backLabel' => 'Zurück',
])

{{--
    Einheitlicher Seitenkopf für den Adminbereich.
    - $title:       Überschrift (Pflicht)
    - $description:  ein Satz, was diese Seite ist und wozu (optional)
    - $back:        URL für den Zurück-Link über dem Titel (optional)
    - Slot "actions": rechtsbündige Buttons, brechen auf Mobil unter den Titel um
--}}
<div {{ $attributes->merge(['class' => 'mb-6 border-b border-gray-200 pb-4']) }}>
    @if ($back)
        <a href="{{ $back }}" class="inline-flex items-center gap-1 text-sm text-laravel-600 hover:text-laravel-700">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
            {{ $backLabel }}
        </a>
    @endif

    <div class="mt-1 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold text-gray-900">{{ $title }}</h1>
            @if ($description)
                <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
