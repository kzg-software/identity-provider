@php
    $version = \App\Support\Version::current();
    $isRelease = \App\Support\Version::isRelease();
    $repoUrl = \App\Services\UpdateChecker::repositoryUrl();
    $versionUrl = \App\Services\UpdateChecker::releaseUrl($version);
    $status = \App\Services\UpdateChecker::status();
    $showUpdate = ($status['update_available'] ?? false) && (bool) (auth()->user()?->is_admin);
@endphp

<footer class="border-t border-gray-200 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-x-4 gap-y-2 text-xs text-gray-500">
        <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
            <span>{{ $systemName }}</span>
            <span aria-hidden="true" class="text-gray-300">&bull;</span>
            @if ($isRelease)
                <a href="{{ $versionUrl }}" target="_blank" rel="noopener noreferrer"
                   class="font-medium text-gray-600 hover:text-laravel-700">{{ $version }}</a>
            @else
                <span class="font-medium text-gray-600">{{ $version }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
            @if ($showUpdate)
                <a href="{{ route('admin.updates.index') }}"
                   class="inline-flex items-center gap-1 font-medium text-laravel-600 hover:text-laravel-700">
                    <x-icon name="sparkles" class="h-3.5 w-3.5" />
                    Update verfügbar: {{ $status['latest'] }}
                </a>
            @endif
            <a href="{{ $repoUrl }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-1.5 hover:text-gray-700">
                <x-icon name="github" class="h-4 w-4" />
                Quellcode
            </a>
        </div>
    </div>
</footer>
