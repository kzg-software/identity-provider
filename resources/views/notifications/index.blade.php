@extends('layouts.admin')

@section('admin-content')
<x-page-header
    title="Benachrichtigungen"
    description="Alle Hinweise, die für dich relevant sind.">
    <x-slot:actions>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-button type="submit" variant="secondary" size="sm">
                    <x-icon name="check" class="h-4 w-4" />Alle als gelesen
                </x-button>
            </form>
        @endif
        @if ($notifications->total() > 0)
            <x-confirm-form :action="route('notifications.clear')"
                            title="Benachrichtigungen leeren"
                            message="Alle Benachrichtigungen entfernen? Das lässt sich nicht rückgängig machen."
                            label="Leeren" variant="danger" size="sm" icon="trash" />
        @endif
    </x-slot:actions>
</x-page-header>

@if ($notifications->isEmpty())
    <x-empty-state icon="bell" title="Keine Benachrichtigungen">
        Sobald es etwas Relevantes gibt, erscheint es hier und an der Glocke in der Navigationsleiste.
    </x-empty-state>
@else
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <ul class="divide-y divide-gray-100">
            @foreach ($notifications as $note)
                <li class="flex items-start gap-3 px-4 py-3.5 {{ $note->isUnread() ? 'bg-laravel-50' : '' }}">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $note->accentClasses() }}">
                        <x-icon :name="$note->iconName()" class="h-4 w-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-sm font-medium text-gray-900">{{ $note->title }}</span>
                            @if ($note->isUnread())
                                <span class="inline-flex items-center rounded-full bg-laravel-600 px-2 py-0.5 text-[11px] font-medium text-white">Neu</span>
                            @endif
                        </div>
                        @if ($note->body)
                            <p class="mt-0.5 text-sm text-gray-500">{{ $note->body }}</p>
                        @endif
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <span class="text-gray-400" title="{{ $note->created_at->format('d.m.Y H:i') }}">{{ $note->created_at->diffForHumans() }}</span>
                            @if ($note->action_url)
                                <a href="{{ route('notifications.open', $note) }}" class="font-medium text-laravel-600 hover:text-laravel-700">Öffnen</a>
                            @endif
                            @if ($note->isUnread())
                                <form method="POST" action="{{ route('notifications.read', $note) }}">
                                    @csrf
                                    <button type="submit" class="font-medium text-gray-500 hover:text-gray-700">Als gelesen</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('notifications.destroy', $note) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-medium text-gray-400 hover:text-red-600">Entfernen</button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endif
@endsection
