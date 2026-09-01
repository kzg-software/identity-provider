@props(['name' => null])

<input type="checkbox" {{ $attributes->merge(['class' => 'h-4 w-4 rounded border-gray-300 text-laravel-600 shadow-sm cursor-pointer transition focus:ring-2 focus:ring-laravel-500 focus:ring-offset-0']) }} @if($name) name="{{ $name }}" @endif>
