@props(['title' => 'Gefahrenzone', 'description' => null])

{{--
    Einheitlicher Bereich für unumkehrbare Aktionen (löschen, Secret neu erzeugen …)
    am Ende einer Detailseite.
--}}
<div {{ $attributes->merge(['class' => 'rounded-lg border border-red-200 bg-red-50 p-4 sm:p-6']) }}>
    <h3 class="text-base font-semibold text-red-800">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 text-sm text-red-700">{{ $description }}</p>
    @endif
    <div class="mt-4 flex flex-wrap items-center gap-3">
        {{ $slot }}
    </div>
</div>
