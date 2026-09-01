@props(['action', 'method' => 'DELETE', 'title' => 'Bitte bestätigen', 'message' => 'Bist du sicher?', 'label' => 'Löschen', 'variant' => 'danger', 'size' => 'sm', 'icon' => 'trash'])

<div x-data="{ open: false }" class="inline">
    <x-button type="button" variant="{{ $variant }}" size="{{ $size }}" @click="open = true" {{ $attributes }}>
        @if ($icon)
            <x-icon :name="$icon" class="h-4 w-4" />
        @endif
        {{ $label }}
    </x-button>

    <div x-show="open" x-cloak style="display:none" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="open = false">
        <div class="fixed inset-0 bg-gray-900/50" @click="open = false"></div>

        <div x-show="open" x-transition class="relative bg-white rounded-lg shadow-xl max-w-sm w-full p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-2">{{ $title }}</h3>
            <p class="text-sm text-gray-600 mb-6">{{ $message }}</p>
            <div class="flex justify-end gap-3">
                <x-button type="button" variant="secondary" size="sm" @click="open = false">Abbrechen</x-button>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    @if (strtoupper($method) !== 'POST')
                        @method($method)
                    @endif
                    <x-button type="submit" variant="{{ $variant }}" size="sm">{{ $label }}</x-button>
                </form>
            </div>
        </div>
    </div>
</div>
