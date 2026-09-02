@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Systemprüfung</h2>
<p class="text-sm text-gray-500 mb-5">Diese Voraussetzungen müssen auf dem Server erfüllt sein, bevor es weitergeht.</p>

<ul class="divide-y divide-gray-200 border border-gray-200 rounded-lg mb-6 overflow-hidden">
    @foreach ($checks as $check)
        <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
            <span class="flex items-center gap-2 text-gray-700">
                @if ($check['ok'])
                    <svg class="h-4 w-4 text-emerald-600 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                @else
                    <svg class="h-4 w-4 text-red-600 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.7 7.3a1 1 0 0 0-1.4 1.4L8.6 10l-1.3 1.3a1 1 0 1 0 1.4 1.4L10 11.4l1.3 1.3a1 1 0 0 0 1.4-1.4L11.4 10l1.3-1.3a1 1 0 0 0-1.4-1.4L10 8.6 8.7 7.3Z" clip-rule="evenodd"/></svg>
                @endif
                {{ $check['label'] }}
            </span>
            <span class="text-xs text-gray-500 text-right shrink-0">{{ $check['detail'] }}</span>
        </li>
    @endforeach
</ul>

<form method="POST" action="{{ route('install.requirements.continue') }}">
    @csrf
    <x-button type="submit">Weiter</x-button>
</form>
@endsection
