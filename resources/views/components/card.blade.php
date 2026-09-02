@props(['title' => null, 'description' => null, 'icon' => null, 'padding' => true])

<div {{ $attributes->merge(['class' => 'bg-white shadow-sm sm:rounded-lg border border-gray-200']) }}>
    @if ($title)
        <div class="px-4 py-3 sm:px-6 border-b border-gray-200">
            <div class="flex items-start gap-2.5">
                @if ($icon)
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-laravel-50 text-laravel-600">
                        <x-icon :name="$icon" class="h-4 w-4" />
                    </span>
                @endif
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>
                    @if ($description)
                        <p class="mt-0.5 text-sm text-gray-500">{{ $description }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
    <div class="{{ $padding ? 'p-4 sm:p-6' : '' }}">
        {{ $slot }}
    </div>
</div>
