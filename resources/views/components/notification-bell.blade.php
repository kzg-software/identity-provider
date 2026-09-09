@php
    $me = auth()->user();

    if ($me?->is_admin && \App\Support\SystemNotifications::isStale()) {
        \Illuminate\Support\Facades\Cache::put('notifications:last_system_sync', now()->toDateTimeString(), now()->addMinutes(15));
        dispatch(fn () => \App\Support\SystemNotifications::sync())->afterResponse();
    } elseif ($me && ! \Illuminate\Support\Facades\Cache::has('notifications:last_prune')) {
        \Illuminate\Support\Facades\Cache::put('notifications:last_prune', 1, now()->addHour());
        dispatch(fn () => \App\Support\SystemNotifications::pruneReadNotifications())->afterResponse();
    }

    $items = $me ? $me->notifications()->limit(8)->get() : collect();
    $unread = $me ? $me->notifications()->unread()->count() : 0;
@endphp

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open"
            class="relative flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700"
            aria-label="Benachrichtigungen{{ $unread > 0 ? ' ('.$unread.' ungelesen)' : '' }}">
        <x-icon name="bell" class="h-5 w-5" />
        @if ($unread > 0)
            <span class="absolute -right-0.5 -top-0.5 flex min-w-[1.15rem] items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-semibold leading-4 text-white ring-2 ring-white">
                {{ $unread > 99 ? '99+' : $unread }}
            </span>
        @endif
    </button>

    <div x-show="open" x-transition style="display:none"
         class="fixed inset-x-3 top-16 z-50 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg
                sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[22rem]">
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
            <span class="text-sm font-semibold text-gray-800">Benachrichtigungen</span>
            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-laravel-600 hover:text-laravel-700">Alle als gelesen</button>
                </form>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($items as $note)
                <a href="{{ route('notifications.open', $note) }}"
                   class="flex gap-3 px-4 py-3 text-sm transition hover:bg-gray-50 {{ $note->isUnread() ? 'bg-laravel-50' : '' }} border-b border-gray-100 last:border-0">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $note->accentClasses() }}">
                        <x-icon :name="$note->iconName()" class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-2">
                            <span class="font-medium text-gray-900 {{ $note->isUnread() ? '' : 'text-gray-600' }}">{{ $note->title }}</span>
                            @if ($note->isUnread())
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-laravel-500"></span>
                            @endif
                        </span>
                        @if ($note->body)
                            <span class="mt-0.5 block text-xs text-gray-500 line-clamp-2">{{ $note->body }}</span>
                        @endif
                        <span class="mt-1 block text-[11px] text-gray-400">{{ $note->created_at->diffForHumans() }}</span>
                    </span>
                </a>
            @empty
                <div class="px-4 py-10 text-center">
                    <span class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <x-icon name="bell" class="h-5 w-5" />
                    </span>
                    <p class="text-sm text-gray-500">Keine Benachrichtigungen.</p>
                </div>
            @endforelse
        </div>

        <div class="border-t border-gray-100 px-4 py-2 text-center">
            <a href="{{ route('notifications.index') }}" class="text-xs font-medium text-laravel-600 hover:text-laravel-700">Alle anzeigen</a>
        </div>
    </div>
</div>
