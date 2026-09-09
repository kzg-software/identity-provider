@props(['href', 'title', 'icon'])

<a href="{{ $href }}"
   class="group flex h-full flex-col rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-laravel-300 hover:shadow-md">
    <div class="flex items-start justify-between gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-laravel-50 text-laravel-600">
            <x-icon :name="$icon" class="h-5 w-5" />
        </span>
        <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:text-laravel-600" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
        </svg>
    </div>
    <h2 class="mt-3 text-sm font-semibold text-gray-900">{{ $title }}</h2>
    <p class="mt-1 text-sm text-gray-500">{{ $slot }}</p>
</a>
